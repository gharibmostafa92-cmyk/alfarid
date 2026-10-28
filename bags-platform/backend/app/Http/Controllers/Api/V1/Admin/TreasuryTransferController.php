<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\TreasuryTransfer;
use App\Services\BranchAccess;
use App\Services\OperationIdempotencyService;
use App\Services\TreasuryService;
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
    public static function middleware(): array
    {
        return [
            new Middleware('can:treasury_transfers.view', only: ['index', 'show']),
            new Middleware('can:treasury_transfers.create', only: ['store']),
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
            'status' => ['nullable', Rule::in(array_column(TreasuryTransferStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branchAccess->restrictedBranch($request->user());
        $transfers = TreasuryTransfer::query()->when($branch !== null, fn ($q) => $q->whereHas('fromTreasury', fn ($q) => $q->where('branch_id', $branch))->whereHas('toTreasury', fn ($q) => $q->where('branch_id', $branch)))
            ->with(['fromTreasury', 'toTreasury', 'creator', 'approver'])
            ->when($filters['from_treasury_id'] ?? null, fn ($q, $v) => $q->where('from_treasury_id', $v))
            ->when($filters['to_treasury_id'] ?? null, fn ($q, $v) => $q->where('to_treasury_id', $v))
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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->idempotency->run(
            'treasury_transfer',
            $request->user(),
            $request->header('Idempotency-Key'),
            $data,
            fn () => $this->treasury->createTransfer($data, (int) $request->user()->id)
        );

        return response()->json([
            'message' => 'تم إنشاء طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'data' => $transfer->load(['fromTreasury', 'toTreasury', 'creator']),
        ], 201);
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
        $this->assertTransferAccess((int) $request->user()->id, $treasuryTransfer);

        return response()->json([
            'data' => $treasuryTransfer->load(['fromTreasury', 'toTreasury', 'creator', 'approver']),
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
        $this->assertTransferAccess((int) $request->user()->id, $treasuryTransfer);
        $transfer = $this->treasury->approveTransfer($treasuryTransfer, (int) $request->user()->id);

        return response()->json([
            'message' => 'تم اعتماد تحويل الخزينة وتم تحديث أرصدة الخزائن.',
            'data' => $transfer->load(['fromTreasury', 'toTreasury', 'approver']),
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
        $this->assertTransferAccess((int) $request->user()->id, $treasuryTransfer);
        $transfer = $this->treasury->rejectTransfer($treasuryTransfer, (int) $request->user()->id);

        return response()->json([
            'message' => 'تم رفض تحويل الخزينة.',
            'data' => $transfer,
        ]);
    }

    private function assertTransferAccess(int $userId, TreasuryTransfer $transfer): void
    {
        foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $treasury) {
            $this->branchAccess->assertTreasury($userId, $treasury);
        }
    }
}
