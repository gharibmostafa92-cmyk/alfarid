<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Inventory\StockLevelResource;
use App\Http\Resources\Admin\Inventory\StockTransferResource;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SearchTerm;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Handles POS terminal inventory queries, branch transfer requests, incoming receipts, and barcode stocktake scans.
 */
class PosStockController extends Controller
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly StocktakeService $stocktakes,
    ) {}

    /**
     * Lists current branch inventory stock levels with search and low-stock filters.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated stock level resources
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        // Non-blind count design: on_hand stays
        // visible to counters because the same handset sells from it — the
        // session relies on the snapshot/stale-movement guards at approve
        // time (StocktakeService) instead of balance secrecy.
        $levels = StockLevel::query()
            ->where('branch_id', $user->branch_id)
            ->with(['variant.product', 'location'])
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['q'] ?? null, function ($q, $v): void {
                // Escape LIKE wildcard characters to avoid malformed patterns and unintentional wildcards.
                $like = SearchTerm::pattern((string) $v);
                $q->whereHas('variant', fn ($vq) => $vq->where('sku', $v)
                    ->orWhere('barcode', $v)
                    ->orWhereHas('product', fn ($pq) => $pq
                        ->where('name_ar', 'LIKE', $like)
                        ->orWhere('name_en', 'LIKE', $like)));
            })
            // Matches alert thresholds: evaluates row-level minimum per location, and variant reorder point across branch totals.
            ->when($filters['low_stock'] ?? null, fn ($q) => $q->whereLowStock())
            ->orderBy('product_variant_id')
            ->paginate($filters['per_page'] ?? 15);

        return StockLevelResource::collection($levels);
    }

    /**
     * Requests stock transfer from another active branch into the cashier's branch.
     *
     * @param Request $request Transfer request payload
     * @return JsonResponse Created transfer response
     */
    public function requestTransfer(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('transfers.create'), 403);

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'min:1',
                Rule::exists('branches', 'id')->where('is_active', true),
                Rule::notIn([$user->branch_id]),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            // Reject inactive variants upfront during validation rather than during transfer approval.
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct',
                Rule::exists('product_variants', 'id')->where('is_active', true)],
            'items.*.from_location_id' => ['nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', (int) $request->input('from_branch_id')),
            ],
            'items.*.to_location_id' => ['nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', (int) $user->branch_id),
            ],
            'items.*.requested_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data['to_branch_id'] = $user->branch_id;

        $transfer = $this->transfers->create($data, $user->id, $this->requiredIdempotencyKey($request));

        return response()->json([
            'message' => 'تم إرسال طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'transfer_number' => $transfer->transfer_number,
            'id' => $transfer->id,
        ], 201);
    }

    /**
     * Confirms receipt of an incoming transfer at the cashier's branch.
     *
     * @param Request $request Receipt request payload
     * @param StockTransfer $transfer Target transfer model
     * @return JsonResponse Receipt confirmation response
     */
    public function receiveTransfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('transfers.receive'), 403);

        if ((int) $transfer->to_branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'هذه التحويلة ليست موجهة لفرعك.'], 403);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct',
                Rule::exists('stock_transfer_items', 'id')->where('stock_transfer_id', $transfer->id),
            ],
            'items.*.received_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ]);

        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['id']] = (float) $item['received_qty'];
        }

        $transfer = $this->transfers->receive($transfer, $quantities, $user->id);

        return response()->json([
            'message' => 'تم تأكيد استلام التحويلة بنجاح وتم رفع المخزون.',
            'id' => $transfer->id,
            'status' => $transfer->status->value,
        ]);
    }

    /**
     * Records barcode scan count against an open stocktake session.
     *
     * @param Request $request Scan request containing barcode and optional quantity
     * @param Stocktake $stocktake Target stocktake session
     * @return JsonResponse Scan registration response
     */
    public function scan(Request $request, Stocktake $stocktake): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('stocktakes.update'), 403);

        if ((int) $stocktake->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'جلسة الجرد لا تتبع فرعك.'], 403);
        }
        app(BranchAccess::class)->assertBranch($user->id, $stocktake->branch_id);

        if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return response()->json(['message' => 'جلسة الجرد ليست مفتوحة.'], 422);
        }

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
            'qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0.0001', 'max:99999'],
            'qty_words' => ['nullable', 'string', 'max:50'],
            'scan_id' => ['nullable', 'uuid'],
        ]);

        // Voice count input: parse spoken Arabic quantities while keeping system_qty hidden from non-approvers.
        $qty = isset($data['qty']) ? (float) $data['qty'] : 1;
        if (! isset($data['qty']) && isset($data['qty_words'])) {
            $spoken = \App\Services\Shared\SpokenQuantity::parse($data['qty_words']);
            if ($spoken === null || $spoken < 1) {
                return response()->json(['message' => 'العدد المنطوق مش واضح؛ قوله تاني أو اكتبه رقمًا.'], 422);
            }
            $qty = $spoken;
        }

        $variant = ProductVariant::query()
            ->where('barcode', $data['barcode'])
            ->where('is_active', true)
            ->first();

        if ($variant === null) {
            return response()->json([
                'message' => 'لم يُعثر على صنف بهذا الباركود.',
                'barcode' => $data['barcode'],
            ], 404);
        }

        $before = $stocktake->items->firstWhere('product_variant_id', $variant->id)?->counted_qty;

        $stocktake = $this->stocktakes->incrementItemCount($stocktake, $variant->id, $qty, $user->id, $data['scan_id'] ?? null);

        $item = $stocktake->items->firstWhere('product_variant_id', $variant->id);

        $response = [
            'message' => 'تم تسجيل المسح بنجاح.',
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'counted_qty' => $item?->counted_qty,
        ];
        if (($data['scan_id'] ?? null) !== null && (float) ($item?->counted_qty ?? 0) === (float) ($before ?? 0)) {
            $response['duplicate'] = true;
        }
        if ($user->can('stocktakes.approve')) {
            $response['system_qty'] = $item?->system_qty;
        }

        return response()->json($response);
    }

    /**
     * Lists incoming shipped transfers targeted at the cashier's branch.
     */
    public function incoming(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user('staff');
        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $transfers = StockTransfer::query()
            ->where('to_branch_id', $branchId)
            ->where('status', TransferStatus::Shipped)
            ->with(['fromBranch', 'toBranch', 'items.variant.product', 'items.fromLocation', 'items.toLocation'])
            ->orderByDesc('shipped_at')
            ->orderByDesc('id')
            ->get();

        return StockTransferResource::collection($transfers);
    }

    /**
     * Lists active transfer source branches and warehouses excluding the cashier's own branch.
     */
    public function sources(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $branches = Branch::query()
            ->where('is_active', true)
            ->where('id', '!=', $branchId)
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $b): array => [
                'id' => $b->id,
                'name' => $b->getAttribute('name_ar') ?? $b->name,
                'code' => $b->code,
                'type' => $b->type instanceof \BackedEnum ? $b->type->value : (string) $b->type,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $branches]);
    }

    /**
     * Retrieves the latest active (draft or in_progress) stocktake session for the cashier's branch.
     *
     * Enforces blind counting by hiding system_qty and variance_qty unless the staff member has stocktakes.approve.
     */
    public function currentStocktake(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $stocktake = Stocktake::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [StocktakeStatus::Draft, StocktakeStatus::InProgress])
            ->with(['items.variant.product'])
            ->latest('id')
            ->first();

        if ($stocktake === null) {
            return response()->json(['data' => null]);
        }

        $canApprove = $user->can('stocktakes.approve');

        $items = $stocktake->items->map(function ($item) use ($canApprove): array {
            $row = [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->relationLoaded('variant') ? $item->variant?->sku : null,
                'barcode' => $item->relationLoaded('variant') ? $item->variant?->barcode : null,
                'counted_qty' => $item->counted_qty !== null ? (float) $item->counted_qty : null,
            ];

            if ($canApprove) {
                $row['system_qty'] = $item->system_qty !== null ? (float) $item->system_qty : null;
                $row['difference'] = $item->difference !== null ? (float) $item->difference : null;
                $row['variance_qty'] = $item->difference !== null ? (float) $item->difference : null;
            }

            return $row;
        })->values()->all();

        return response()->json([
            'data' => [
                'id' => $stocktake->id,
                'stocktake_number' => $stocktake->stocktake_number,
                'branch_id' => $stocktake->branch_id,
                'location_id' => $stocktake->location_id,
                'status' => $stocktake->status->value,
                'notes' => $stocktake->notes,
                'items' => $items,
                'created_at' => $stocktake->created_at,
                'updated_at' => $stocktake->updated_at,
            ],
        ]);
    }
}
