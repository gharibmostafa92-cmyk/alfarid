<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\User;
use App\Services\OperationIdempotencyService;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم المبيعات في نقطة البيع.
 * يتعامل مع: البيع المباشر، التعليق، المرتجع، والاستبدال.
 */
class PosSaleController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * POST /pos/sales — إنشاء فاتورة بيع مباشر.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,mixed'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            'override_price' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        // التحقق من صلاحية تجاوز السعر
        if (! empty($data['override_price'])) {
            $user = $request->user('staff');
            if (! $user->can('pos.override_price')) {
                return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
            }
        }

        $order = app(OperationIdempotencyService::class)->run(
            'pos_sale', $request->user('staff'), $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, ...$data], fn () => $this->pos->createSale($shift, $data),
        );

        return response()->json([
            'message' => 'تم تسجيل البيع بنجاح.',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * POST /pos/sales/hold — تعليق فاتورة مؤقتاً.
     */
    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $held = $this->pos->holdSale($shift, $data);

        return response()->json([
            'message' => 'تم تعليق الفاتورة بنجاح.',
            'hold_reference' => $held->hold_reference,
            'data' => $held->only(['id', 'hold_reference', 'items_payload', 'notes', 'created_at']),
        ], 201);
    }

    /**
     * GET /pos/sales/held — قائمة الفواتير المعلقة في الفرع.
     */
    public function held(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        $held = PosHeldSale::query()
            ->where('branch_id', $user->branch_id)
            ->with(['customer'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $held]);
    }

    /**
     * POST /pos/sales/{id}/return — مرتجع من فاتورة POS.
     */
    public function processReturn(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $sale->load('items');

        $result = $this->pos->processReturn($shift, $sale, $data['items']);

        return response()->json([
            'message' => 'تم تسجيل المرتجع بنجاح.',
            'data' => $result,
        ]);
    }

    /**
     * POST /pos/sales/{id}/exchange — استبدال من فاتورة POS.
     */
    public function processExchange(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'returned_items' => ['required', 'array', 'min:1'],
            'returned_items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'returned_items.*.qty' => ['required', 'integer', 'min:1'],
            'returned_items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
            'exchange_items' => ['required', 'array', 'min:1'],
            'exchange_items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'exchange_items.*.qty' => ['required', 'integer', 'min:1'],
            'exchange_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'exchange_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $sale->load('items');

        // C2: الاستبدال = مرتجع مستقل ثم بيع مستقل بمبالغه الخاصة.
        $exchangePayload = [
            'items' => $data['exchange_items'],
            'customer_id' => $sale->customer_id,
        ];
        if (array_key_exists('cash_amount', $data)) {
            $exchangePayload['cash_amount'] = $data['cash_amount'];
        }
        if (array_key_exists('card_amount', $data)) {
            $exchangePayload['card_amount'] = $data['card_amount'];
        }
        $result = DB::transaction(function () use ($shift, $sale, $data, $exchangePayload): array {
            $result = $this->pos->processReturn($shift, $sale, $data['returned_items']);
            $exchangeOrder = $this->pos->createSale($shift, $exchangePayload);
            $result['exchange_order_id'] = $exchangeOrder->id;

            return $result;
        }, 3);

        return response()->json([
            'message' => 'تم تسجيل الاستبدال بنجاح.',
            'data' => $result,
        ]);
    }

    // ===================================================================
    //  Helper
    // ===================================================================

    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'status' => $order->status->value,
            'grand_total' => $order->grand_total,
            'cash_amount' => $order->cash_amount,
            'card_amount' => $order->card_amount,
            'customer' => $order->relationLoaded('customer') ? [
                'id' => $order->customer?->id,
                'name' => $order->customer?->name,
            ] : null,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($i) => [
                'product_variant_id' => $i->product_variant_id,
                'sku' => $i->relationLoaded('variant') ? $i->variant?->sku : null,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount,
                'line_total' => $i->line_total,
            ])->all() : [],
        ];
    }
}
