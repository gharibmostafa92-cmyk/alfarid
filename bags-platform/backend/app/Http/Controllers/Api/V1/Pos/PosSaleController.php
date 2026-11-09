<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\OperationIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Pos\PosService;
use App\Services\Shared\SettingService;
use App\Support\ArabicDigits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POS terminal sales operations: direct checkout, cart hold/resume, returns, and item exchanges.
 */
class PosSaleController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Create and record a direct POS retail sale within the active shift.
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

        // Authorize manual unit price overrides via pos.override_price permission.
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
        // Idempotent replays resolve via find() without relations; reload for a complete response.
        $order->loadMissing(['items.variant', 'customer']);

        return response()->json([
            'message' => 'تم تسجيل البيع بنجاح.',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * Temporarily hold an uncompleted sale for later retrieval.
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
     * List all held sales currently pending in the cashier's branch.
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
     * Calculate live cart pricing, discounts, available stock, and totals without locks or DB writes.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'override_price' => ['nullable', 'boolean'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $override = ! empty($data['override_price']);
        if ($override && ! $user->can('pos.override_price')) {
            return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
        }

        if (collect($data['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $user->can('pos.discount')) {
            return response()->json(['message' => 'لا تملك صلاحية تطبيق خصم يدوي.'], 403);
        }

        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        return response()->json([
            'data' => $this->pos->quote(
                branchId: (int) $shift->branch_id,
                items: $data['items'],
                c: $customer,
                override: $override,
            ),
        ]);
    }

    /**
     * Look up a delivered POS sale order by number for return or exchange processing.
     */
    public function lookupForReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $normalized = strtoupper(trim(ArabicDigits::normalize($data['number'])));
        if ($normalized === '') {
            abort(404, 'الفاتورة غير موجودة.');
        }

        $candidates = [$normalized];
        if (! str_starts_with($normalized, 'POS-')) {
            $candidates[] = 'POS-'.$normalized;
        }
        if (! str_starts_with($normalized, 'ORD-')) {
            $candidates[] = 'ORD-'.$normalized;
        }

        $stripped = preg_replace('/^(?:POS|ORD)-/', '', $normalized) ?? $normalized;
        if (ctype_digit($stripped)) {
            $intVal = (string) ((int) $stripped);
            $pad6 = str_pad($intVal, 6, '0', STR_PAD_LEFT);
            $pad4 = str_pad($intVal, 4, '0', STR_PAD_LEFT);
            foreach (['POS-', 'ORD-'] as $p) {
                $candidates[] = $p.$stripped;
                $candidates[] = $p.$intVal;
                $candidates[] = $p.$pad6;
                $candidates[] = $p.$pad4;
            }
            $orderPrefix = (string) app(SettingService::class)->get('order_prefix', 'ORD-');
            $orderPadding = (int) app(SettingService::class)->get('order_padding', 6);
            if ($orderPrefix !== '') {
                $candidates[] = strtoupper($orderPrefix).str_pad($intVal, max(0, $orderPadding), '0', STR_PAD_LEFT);
                $candidates[] = strtoupper($orderPrefix).$stripped;
            }
        }
        $candidates = array_values(array_unique($candidates));
        $literalSuffix = strtr($stripped, ['!' => '!!', '%' => '!%', '_' => '!_']);

        $matchCandidates = function ($q) use ($candidates, $stripped, $literalSuffix): void {
            $q->whereIn('order_number', $candidates);
            if ($stripped !== '') {
                $q->orWhereRaw("order_number LIKE ? ESCAPE '!'", ['%-'.$literalSuffix]);
                if (ctype_digit($stripped)) {
                    $intVal = (string) ((int) $stripped);
                    $q->orWhere('order_number', 'like', '%-'.str_pad($intVal, 6, '0', STR_PAD_LEFT));
                }
            }
        };

        $baseQuery = fn () => Order::query()
            ->where('channel', 'pos')
            ->where('status', OrderStatus::Delivered)
            ->whereNull('return_of_order_id')
            ->where($matchCandidates)
            ->with(['items.variant.product', 'customer', 'posShift.cashier']);

        $order = $baseQuery()->latest('id')->first();

        if ($order === null) {
            abort(404, 'الفاتورة غير موجودة.');
        }

        // Prefer an invoice from the cashier's own branch before falling back to
        // the globally latest match, so a branch-restricted cashier finds their
        // own invoice instead of failing on another branch's newer invoice.
        try {
            $scopedBranch = app(BranchAccess::class)->restrictedBranch($user);
        } catch (AuthorizationException) {
            $scopedBranch = false;
        }
        if (is_int($scopedBranch)) {
            $orderBranchId = (int) ($order->allocated_branch_id ?? $order->posShift?->branch_id ?? 0);
            if ($orderBranchId !== $scopedBranch) {
                $scoped = $baseQuery()
                    ->where(function ($q) use ($scopedBranch): void {
                        $q->where('allocated_branch_id', $scopedBranch)
                            ->orWhereHas('posShift', fn ($s) => $s->where('branch_id', $scopedBranch));
                    })
                    ->latest('id')
                    ->first();
                if ($scoped !== null) {
                    $order = $scoped;
                }
            }
        }

        $branchId = (int) ($order->allocated_branch_id ?? $order->posShift?->branch_id ?? 0);
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'payment_method' => $order->payment_method,
                'cash_amount' => number_format((float) $order->cash_amount, 2, '.', ''),
                'card_amount' => number_format((float) $order->card_amount, 2, '.', ''),
                'grand_total' => number_format((float) $order->grand_total, 2, '.', ''),
                'customer' => $order->customer !== null ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                ] : null,
                'cashier' => $order->posShift?->cashier !== null ? [
                    'id' => $order->posShift->cashier->id,
                    'name' => $order->posShift->cashier->name,
                ] : null,
                'items' => $order->items->map(function (OrderItem $item): array {
                    $qty = (float) $item->qty;
                    $returnedQty = (float) ($item->returned_qty ?? 0);
                    $returnableQty = max(0.0, $qty - $returnedQty);
                    $unitPrice = (float) $item->unit_price;
                    $discountAmount = (float) $item->discount_amount;
                    $netUnitPrice = $qty > 0 ? ($qty * $unitPrice - $discountAmount) / $qty : 0.0;

                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'product_name' => $item->product_name_snapshot
                            ?? $item->variant?->product?->name_ar
                            ?? $item->variant?->product?->name_en,
                        'variant_name' => $item->variant?->color_name_ar ?? $item->variant?->color_name_en,
                        'sku' => $item->sku_snapshot ?? $item->variant?->sku,
                        'qty' => floor($qty) === $qty ? (int) $qty : $qty,
                        'returned_qty' => floor($returnedQty) === $returnedQty ? (int) $returnedQty : $returnedQty,
                        'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
                        'unit_price' => number_format($unitPrice, 2, '.', ''),
                        'discount_amount' => number_format($discountAmount, 2, '.', ''),
                        'net_unit_price' => number_format($netUnitPrice, 2, '.', ''),
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * Atomically lock, retrieve, and delete a held sale so two cashiers cannot resume it twice.
     */
    public function resumeHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $data = DB::transaction(function () use ($held_sale, $user): array {
            $heldSale = PosHeldSale::query()
                ->whereKey($held_sale)
                ->lockForUpdate()
                ->with('customer')
                ->first();

            if ($heldSale === null) {
                abort(404, 'الفاتورة المعلقة غير موجودة.');
            }

            app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

            $payload = [
                'id' => $heldSale->id,
                'hold_reference' => $heldSale->hold_reference,
                'customer_id' => $heldSale->customer_id,
                'customer' => $heldSale->customer !== null ? [
                    'id' => $heldSale->customer->id,
                    'name' => $heldSale->customer->name,
                    'phone' => $heldSale->customer->phone,
                ] : null,
                'notes' => $heldSale->notes,
                'items_payload' => $heldSale->items_payload,
            ];

            $heldSale->delete();

            return $payload;
        });

        return response()->json([
            'message' => 'تم استعادة الفاتورة المعلقة بنجاح.',
            'data' => $data,
        ]);
    }

    /**
     * Delete a parked held sale belonging to the cashier's branch.
     */
    public function deleteHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $heldSale = PosHeldSale::query()->find($held_sale);
        if ($heldSale === null) {
            abort(404, 'الفاتورة المعلقة غير موجودة.');
        }

        app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

        $heldSale->delete();

        return response()->json([
            'message' => 'تم حذف الفاتورة المعلقة بنجاح.',
        ]);
    }

    /**
     * Process item return against an existing POS sale order.
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
     * Process exchange: atomically return items and create new replacement sale order.
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

        // Atomically process return and replacement sale order in a single transaction.
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

    /**
     * Formats order and line items for POS receipt printer and client payload.
     *
     * @param  Order  $order  Target order model
     * @return array<string, mixed> Formatted POS order data
     */
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
