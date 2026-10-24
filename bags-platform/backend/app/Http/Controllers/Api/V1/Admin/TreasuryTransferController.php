<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\TreasuryTransfer;
use App\Services\BranchAccess;
use App\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

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

    public function __construct(private readonly TreasuryService $treasury) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from_treasury_id' => ['nullable', 'integer', 'min:1'],
            'to_treasury_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(TreasuryTransferStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        $transfers = TreasuryTransfer::query()->when($branch !== null, fn ($q) => $q->whereHas('fromTreasury', fn ($q) => $q->where('branch_id', $branch))->whereHas('toTreasury', fn ($q) => $q->where('branch_id', $branch)))
            ->with(['fromTreasury', 'toTreasury', 'creator', 'approver'])
            ->when($filters['from_treasury_id'] ?? null, fn ($q, $v) => $q->where('from_treasury_id', $v))
            ->when($filters['to_treasury_id'] ?? null, fn ($q, $v) => $q->where('to_treasury_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($transfers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id'],
            'to_treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id', 'different:from_treasury_id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->treasury->createTransfer($data, (int) $request->user()->id);

        return response()->json([
            'message' => 'تم إنشاء طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'data' => $transfer->load(['fromTreasury', 'toTreasury', 'creator']),
        ], 201);
    }

    public function show(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        foreach ([$treasuryTransfer->fromTreasury, $treasuryTransfer->toTreasury] as $treasury) app(BranchAccess::class)->assertTreasury($request->user()->id, $treasury);
        return response()->json([
            'data' => $treasuryTransfer->load(['fromTreasury', 'toTreasury', 'creator', 'approver']),
        ]);
    }

    /**
     * POST /admin/treasury-transfers/{id}/approve
     */
    public function approve(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $transfer = $this->treasury->approveTransfer($treasuryTransfer, (int) $request->user()->id);

        return response()->json([
            'message' => 'تم اعتماد تحويل الخزينة وتم تحديث أرصدة الخزائن.',
            'data' => $transfer->load(['fromTreasury', 'toTreasury', 'approver']),
        ]);
    }

    /**
     * POST /admin/treasury-transfers/{id}/reject
     */
    public function reject(Request $request, TreasuryTransfer $treasuryTransfer): JsonResponse
    {
        $transfer = $this->treasury->rejectTransfer($treasuryTransfer, (int) $request->user()->id);

        return response()->json([
            'message' => 'تم رفض تحويل الخزينة.',
            'data' => $transfer,
        ]);
    }
}
