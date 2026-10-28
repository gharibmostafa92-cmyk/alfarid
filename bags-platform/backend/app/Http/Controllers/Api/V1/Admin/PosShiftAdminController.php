<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\PosService;
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
    public static function middleware(): array
    {
        return [
            new Middleware('can:pos_shifts.view', only: ['index', 'show']),
            new Middleware('can:pos_shifts.approve', only: ['approve']),
        ];
    }

    public function __construct(
        private readonly PosService $pos,
        private readonly BranchAccess $branchAccess,
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

        $branch = $this->branchAccess->restrictedBranch($request->user());
        $shifts = PosShift::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->with(['cashier', 'treasury', 'branch', 'approver'])
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
        $this->branchAccess->assertBranch($request->user()->id, $shift->branch_id);

        return response()->json([
            'data' => $shift->load(['cashier', 'treasury', 'branch', 'approver', 'expenses']),
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
        /** @var User $approver */
        $approver = $request->user();
        $this->branchAccess->assertBranch($approver->id, $shift->branch_id);

        $shift = $this->pos->approveShift($shift, $approver);

        return response()->json([
            'message' => 'تم اعتماد الوردية وترحيل النقدية للخزنة الرئيسية.',
            'data' => $shift->load(['cashier', 'treasury', 'branch', 'approver']),
        ]);
    }
}
