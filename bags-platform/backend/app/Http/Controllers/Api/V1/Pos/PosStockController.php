<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\StockLevelResource;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * عمليات المخزون من شاشة الـ POS:
 * - عرض أرصدة الفرع
 * - طلب تحويل بضاعة
 * - تأكيد استلام تحويلة
 * - مسح الباركود في جلسة الجرد
 */
class PosStockController extends Controller
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly StocktakeService $stocktakes,
    ) {}

    /**
     * GET /pos/stock — أرصدة مخزون الفرع الحالي.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'q'                  => ['nullable', 'string', 'max:100'],
            'low_stock'          => ['nullable', 'boolean'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $levels = StockLevel::query()
            ->where('branch_id', $user->branch_id)
            ->with(['variant.product', 'location'])
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) =>
                $vq->where('sku', $v)
                    ->orWhere('barcode', $v)
                    ->orWhereHas('product', fn ($pq) => $pq->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%']))
            ))
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
     * POST /pos/transfer-requests — طلب تحويل بضاعة إلى الفرع الحالي.
     */
    public function requestTransfer(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'min:1', 'different:to_branch_id'],
            'items'          => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.requested_qty'      => ['required', 'numeric', 'min:0.0001'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $data['to_branch_id'] = $user->branch_id;

        $transfer = $this->transfers->create($data, $user->id);

        return response()->json([
            'message'         => 'تم إرسال طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'transfer_number' => $transfer->transfer_number,
            'id'              => $transfer->id,
        ], 201);
    }

    /**
     * POST /pos/transfers/{transfer}/receive — تأكيد استلام تحويلة واردة للفرع.
     */
    public function receiveTransfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        // التحقق أن الفرع المستقبل هو فرع الكاشير
        if ((int) $transfer->to_branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'هذه التحويلة ليست موجهة لفرعك.'], 403);
        }

        $data = $request->validate([
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.id'             => ['required', 'integer', 'min:1'],
            'items.*.received_qty'   => ['required', 'numeric', 'min:0'],
            'items.*.to_location_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $transfer = $this->transfers->receive($transfer, $data, $user->id);

        return response()->json([
            'message' => 'تم تأكيد استلام التحويلة بنجاح وتم رفع المخزون.',
            'id'      => $transfer->id,
            'status'  => $transfer->status->value,
        ]);
    }

    /**
     * POST /pos/stocktakes/{stocktake}/scan — مسح باركود في جلسة جرد مفتوحة.
     *
     * يزيد counted_qty بمقدار 1 (أو بالكمية الممررة) للعنصر الموافق للباركود.
     * إذا لم يوجد العنصر في الجلسة يُنشئه.
     */
    public function scan(Request $request, Stocktake $stocktake): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        // التحقق أن جلسة الجرد تتبع الفرع الحالي
        if ((int) $stocktake->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'جلسة الجرد لا تتبع فرعك.'], 403);
        }

        if ($stocktake->status !== StocktakeStatus::Open) {
            return response()->json(['message' => 'جلسة الجرد ليست مفتوحة.'], 422);
        }

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
            'qty'     => ['nullable', 'numeric', 'min:0.0001', 'max:99999'],
        ]);

        $qty = (float) ($data['qty'] ?? 1);

        // البحث عن المتغير بالباركود
        $variant = \App\Models\ProductVariant::query()
            ->where('barcode', $data['barcode'])
            ->first();

        if ($variant === null) {
            return response()->json([
                'message' => 'لم يُعثر على صنف بهذا الباركود.',
                'barcode' => $data['barcode'],
            ], 404);
        }

        // الحصول على الرصيد الفعلي للتوثيق
        $stock = StockLevel::query()
            ->where('product_variant_id', $variant->id)
            ->where('branch_id', $stocktake->branch_id)
            ->first();

        // upsert عنصر الجرد
        $item = StocktakeItem::query()
            ->where('stocktake_id', $stocktake->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        if ($item === null) {
            $item = StocktakeItem::create([
                'stocktake_id'       => $stocktake->id,
                'product_variant_id' => $variant->id,
                'system_qty'         => (float) ($stock?->on_hand ?? 0),
                'counted_qty'        => $qty,
            ]);
        } else {
            $item->increment('counted_qty', $qty);
            $item->refresh();
        }

        return response()->json([
            'message'            => 'تم تسجيل المسح بنجاح.',
            'product_variant_id' => $variant->id,
            'sku'                => $variant->sku,
            'counted_qty'        => $item->counted_qty,
            'system_qty'         => $item->system_qty,
        ]);
    }
}
