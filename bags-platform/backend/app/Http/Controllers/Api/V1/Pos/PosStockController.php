<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\StockLevelResource;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StocktakeService;
use App\Services\TransferService;
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
            ->when($filters['low_stock'] ?? null, fn ($q) => $q
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                ->whereRaw('stock_levels.available <= COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0)')
                ->whereRaw('COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0) > 0')
                ->select('stock_levels.*')
            )
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
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('product_variants', 'id')],
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
        ]);

        $qty = (float) ($data['qty'] ?? 1);

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

        $stocktake = $this->stocktakes->incrementItemCount($stocktake, $variant->id, $qty, $user->id);

        $item = $stocktake->items->firstWhere('product_variant_id', $variant->id);

        $response = [
            'message' => 'تم تسجيل المسح بنجاح.',
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'counted_qty' => $item?->counted_qty,
        ];
        if ($user->can('stocktakes.approve')) {
            $response['system_qty'] = $item?->system_qty;
        }

        return response()->json($response);
    }
}
