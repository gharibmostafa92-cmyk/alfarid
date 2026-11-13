<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Enums\Finance\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\PosService;
use App\Services\Shared\OperationIdempotencyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Admin controller for reviewing POS shifts, cash discrepancies, and authorizing closures.
 */
class PosShiftAdminController extends Controller implements HasMiddleware
{
    private const RELATIONS = [
        'cashier:id,name',
        'treasury:id,name,code,branch_id,type',
        'branch:id,name',
        'approver:id,name',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:pos_shifts.view', only: ['index', 'show']),
            new Middleware('can:pos_shifts.approve', only: ['approve', 'reject', 'reopen']),
            new Middleware('can:pos_shifts.force_close', only: ['forceClose']),
        ];
    }

    public function __construct(
        private readonly PosService $pos,
        private readonly BranchAccess $branchAccess,
        private readonly OperationIdempotencyService $idempotency,
    ) {}

    /**
     * Lists paginated POS shifts for administrative audit and approval.
     *
     * @param Request $request Filter query request
     * @return JsonResponse Paginated shifts response
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(PosShiftStatus::cases(), 'value'))],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // A branch-scoped admin probing another branch is rejected with 403
        // via assertBranch instead of silently receiving an empty list.
        $branch = $this->branchAccess->restrictedBranch($request->user('staff'));
        if ($branch !== null && isset($filters['branch_id'])) {
            $this->branchAccess->assertBranch($request->user('staff')->id, (int) $filters['branch_id']);
        }
        $shifts = PosShift::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->with(self::RELATIONS)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // Convert business timezone start/end bounds to UTC using an exclusive upper day boundary.
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('opened_at', '>=',
                Carbon::parse($v, (string) config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('opened_at', '<',
                Carbon::parse($v, (string) config('app.business_timezone'))->startOfDay()->addDay()->utc()))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($shifts);
    }

    /**
     * Retrieves full details and petty cash expenses of a specific shift.
     *
     * @param Request $request Incoming request
     * @param PosShift $shift Target shift model
     * @return JsonResponse Shift details response
     */
    public function show(Request $request, PosShift $shift): JsonResponse
    {
        $this->branchAccess->assertBranch($request->user('staff')->id, $shift->branch_id);

        return response()->json([
            'data' => $shift->load([...self::RELATIONS, 'expenses']),
        ]);
    }

    /**
     * Approves a closed shift, reconciling drawer variance and sweeping cash to the main safe.
     *
     * @param Request $request Approval request
     * @param PosShift $shift Shift awaiting approval
     * @return JsonResponse Approved shift response
     */
    public function approve(Request $request, PosShift $shift): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $notes = $data['notes'] ?? null;

        /** @var User $approver */
        $approver = $request->user('staff');
        $this->branchAccess->assertBranch($approver->id, $shift->branch_id);

        $idempotencyKey = $request->header('Idempotency-Key');
        $shift = $idempotencyKey !== null
            ? $this->idempotency->run(
                'pos_shift_approve',
                $approver,
                $idempotencyKey,
                ['shift_id' => $shift->id, 'notes' => $notes],
                fn () => $this->pos->approveShift($shift, $approver, $notes),
            )
            : $this->pos->approveShift($shift, $approver, $notes);

        return response()->json([
            'message' => 'تم اعتماد الوردية وترحيل النقدية للخزنة الرئيسية.',
            'data' => $shift->load(self::RELATIONS),
        ]);
    }

    /**
     * Rejects a pending closure and reopens the shift for a fresh cash count.
     */
    public function reject(Request $request, PosShift $shift): JsonResponse
    {
        return $this->reopen(
            $request,
            $shift,
            'تم رفض الإغلاق وإعادة فتح الوردية لإعادة الجرد.',
            'pos_shift_reject',
        );
    }

    /**
     * Reopens a pending closure for recount.
     */
    public function reopen(
        Request $request,
        PosShift $shift,
        ?string $message = null,
        string $idempotencyOperation = 'pos_shift_reopen',
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /** @var User $approver */
        $approver = $request->user('staff');
        $this->branchAccess->assertBranch($approver->id, $shift->branch_id);

        $idempotencyKey = $request->header('Idempotency-Key');
        $shift = $idempotencyKey !== null
            ? $this->idempotency->run(
                $idempotencyOperation,
                $approver,
                $idempotencyKey,
                ['shift_id' => $shift->id, 'reason' => $data['reason']],
                fn () => $this->pos->reopenShiftForRecount($shift, $approver, $data['reason']),
            )
            : $this->pos->reopenShiftForRecount($shift, $approver, $data['reason']);

        return response()->json([
            'message' => $message ?? 'تمت إعادة فتح الوردية لإعادة الجرد.',
            'data' => $shift->load(self::RELATIONS),
        ]);
    }

    /**
     * Force-closes an open shift at the supervisor's physically counted cash.
     */
    public function forceClose(Request $request, PosShift $shift): JsonResponse
    {
        $data = $request->validate([
            'actual_closing_cash' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /** @var User $approver */
        $approver = $request->user('staff');
        $this->branchAccess->assertBranch($approver->id, $shift->branch_id);

        $normalizedCash = (string) BigDecimal::of((string) $data['actual_closing_cash'])->toScale(2, RoundingMode::Unnecessary);
        $idempotencyKey = $request->header('Idempotency-Key');
        $shift = $idempotencyKey !== null
            ? $this->idempotency->run(
                'pos_shift_force_close',
                $approver,
                $idempotencyKey,
                [
                    'shift_id' => $shift->id,
                    'actual_closing_cash' => $normalizedCash,
                    'reason' => $data['reason'],
                ],
                fn () => $this->pos->forceCloseShift($shift, $approver, $normalizedCash, $data['reason']),
            )
            : $this->pos->forceCloseShift($shift, $approver, $normalizedCash, $data['reason']);

        return response()->json([
            'message' => 'تم الإغلاق القسري للوردية وهي بانتظار الاعتماد.',
            'data' => $shift->load(self::RELATIONS),
        ]);
    }
}
