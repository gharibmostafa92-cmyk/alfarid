<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Enums\Finance\TreasuryTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Pos\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Manages inter-treasury transfer requests, branch permission checks, and approvals.
 */
class TreasuryTransferController extends Controller implements HasMiddleware
{
    private const RELATIONS_INDEX = [
        'fromTreasury:id,name,code,branch_id,type',
        'toTreasury:id,name,code,branch_id,type',
        'creator:id,name',
        'approver:id,name',
        'rejecter:id,name',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:treasury_transfers.view', only: ['index', 'show']),
            new Middleware('can:treasury_transfers.create', only: ['store', 'cancel']),
            new Middleware('can:treasury_transfers.approve', only: ['approve', 'reject']),
        ];
    }

    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly BranchAccess $branchAccess,
        private readonly OperationIdempotencyService $idempotency,
    ) {}

    /**
     * Lists paginated treasury transfers with branch access scoping and status filters.
     *
     * @param Request $request Filter query request
     * @return JsonResponse Paginated transfers
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from_treasury_id' => ['nullable', 'integer', 'min:1'],
            'to_treasury_id' => ['nullable', 'integer', 'min:1'],
            'treasury_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(TreasuryTransferStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $staff = $request->user('staff');
        $branch = $this->branchAccess->restrictedBranch($staff);
        if ($branch !== null) {
            foreach (['from_treasury_id', 'to_treasury_id', 'treasury_id'] as $filterKey) {
                if (isset($filters[$filterKey])) {
                    $probedTreasury = Treasury::query()->find((int) $filters[$filterKey]);
                    if ($probedTreasury === null) {
                        throw new AuthorizationException('الخزينة غير متاحة لفرعك.');
                    }
                    try {
                        $this->branchAccess->assertTreasury($staff->id, $probedTreasury);
                    } catch (AuthorizationException) {
                        // Uniform denial for missing and foreign treasuries to avoid ID enumeration.
                        throw new AuthorizationException('الخزينة غير متاحة لفرعك.');
                    }
                }
            }
        }
        $transfers = TreasuryTransfer::query()->when($branch !== null, function ($q) use ($branch) {
            $branchTreasuryIds = Treasury::query()->where('branch_id', $branch)->pluck('id');

            // Least privilege: branch users only see transfers fully inside their branch.
            return $q->whereIn('from_treasury_id', $branchTreasuryIds)
                ->whereIn('to_treasury_id', $branchTreasuryIds);
        })
            ->with(self::RELATIONS_INDEX)
            ->when($filters['from_treasury_id'] ?? null, fn ($q, $v) => $q->where('from_treasury_id', $v))
            ->when($filters['to_treasury_id'] ?? null, fn ($q, $v) => $q->where('to_treasury_id', $v))
            ->when($filters['treasury_id'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub->where('from_treasury_id', $v)->orWhere('to_treasury_id', $v)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($transfers);
    }

    /**
     * Submits an idempotent pending transfer request between two authorized treasuries.
     *
     * @param Request $request Creation payload
     * @return JsonResponse Created transfer response
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id'],
            'to_treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id', 'different:from_treasury_id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $staff = $request->user('staff');
        $replayed = false;
        $transfer = $this->idempotency->run(
            'treasury_transfer',
            $staff,
            $request->header('Idempotency-Key'),
            $data,
            fn () => $this->treasury->createTransfer($data, (int) $staff->id),
            $replayed,
        );

        return response()->json([
            'message' => 'تم إنشاء طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'data' => $transfer->load(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type', 'creator:id,name']),
        ], $replayed ? 200 : 201);
    }

    /**
     * Retrieves details of a specific transfer request.
     *
     * @param Request $request Incoming request
     * @param TreasuryTransfer $treasuryTransfer Target transfer model
     * @return JsonResponse Transfer details
     */
    public function show(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $treasuryTransfer->loadMissing(self::RELATIONS_INDEX);
        $this->assertTransferReadAccess((int) $request->user('staff')->id, $treasuryTransfer);

        return response()->json([
            'data' => $treasuryTransfer,
        ]);
    }

    /**
     * Approves and executes a pending transfer request.
     *
     * @param Request $request Approval request
     * @param TreasuryTransfer $treasuryTransfer Transfer to approve
     * @return JsonResponse Approved transfer response
     */
    public function approve(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $staff = $request->user('staff');
        $treasuryTransfer->loadMissing(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type']);
        $this->assertTransferAccess((int) $staff->id, $treasuryTransfer);
        $idempotencyKey = $request->header('Idempotency-Key');
        $transfer = $idempotencyKey !== null
            ? $this->idempotency->run(
                'treasury_transfer_approve',
                $staff,
                $idempotencyKey,
                ['transfer_id' => $treasuryTransfer->id],
                fn () => $this->treasury->approveTransfer($treasuryTransfer, (int) $staff->id),
            )
            : $this->treasury->approveTransfer($treasuryTransfer, (int) $staff->id);

        return response()->json([
            'message' => 'تم اعتماد تحويل الخزينة وتم تحديث أرصدة الخزائن.',
            'data' => $transfer->load(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type', 'approver:id,name']),
        ]);
    }

    /**
     * Rejects a pending transfer request.
     *
     * @param Request $request Rejection request
     * @param TreasuryTransfer $treasuryTransfer Transfer to reject
     * @return JsonResponse Rejected transfer response
     */
    public function reject(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $staff = $request->user('staff');
        $treasuryTransfer->loadMissing(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type']);
        $this->assertTransferAccess((int) $staff->id, $treasuryTransfer);
        $idempotencyKey = $request->header('Idempotency-Key');
        $transfer = $idempotencyKey !== null
            ? $this->idempotency->run(
                'treasury_transfer_reject',
                $staff,
                $idempotencyKey,
                ['transfer_id' => $treasuryTransfer->id, 'reason' => $data['reason']],
                fn () => $this->treasury->rejectTransfer($treasuryTransfer, (int) $staff->id, $data['reason']),
            )
            : $this->treasury->rejectTransfer($treasuryTransfer, (int) $staff->id, $data['reason']);

        return response()->json([
            'message' => 'تم رفض تحويل الخزينة.',
            'data' => $transfer->load(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type', 'rejecter:id,name']),
        ]);
    }

    /**
     * Cancels a pending transfer owned by the requesting creator.
     */
    public function cancel(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $staff = $request->user('staff');
        $treasuryTransfer->loadMissing(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type']);
        $this->assertTransferAccess((int) $staff->id, $treasuryTransfer);
        $idempotencyKey = $request->header('Idempotency-Key');
        $transfer = $idempotencyKey !== null
            ? $this->idempotency->run(
                'treasury_transfer_cancel',
                $staff,
                $idempotencyKey,
                ['transfer_id' => $treasuryTransfer->id],
                fn () => $this->treasury->cancelTransfer($treasuryTransfer, (int) $staff->id),
            )
            : $this->treasury->cancelTransfer($treasuryTransfer, (int) $staff->id);

        return response()->json([
            'message' => 'تم إلغاء طلب تحويل الخزينة.',
            'data' => $transfer->load(['fromTreasury:id,name,code,branch_id,type', 'toTreasury:id,name,code,branch_id,type', 'rejecter:id,name']),
        ]);
    }

    private function assertTransferReadAccess(int $userId, TreasuryTransfer $transfer): void
    {
        // Reads enforce the same two-sided rule as approvals so a branch user
        // can never inspect the other side's treasury details or notes.
        $this->assertTransferAccess($userId, $transfer);
    }

    private function assertTransferAccess(int $userId, TreasuryTransfer $transfer): void
    {
        foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $treasury) {
            $this->branchAccess->assertTreasury($userId, $treasury);
        }
    }
}
