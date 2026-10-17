<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * خدمات الطلب.
 * - createFromCart: تحويل سلة إلى طلب بلقطات أسعار من PricingService،
 *   وخصم الكوبون، وشحن المدينة، وضريبة الإعدادات، ثم إفراغ السلة.
 * - changeStatus: بوابة تغيير الحالة الوحيدة — تتحقق من آلة الحالات،
 *   وتسجل التغيير في order_status_histories، وتدير آثار المخزون:
 *   confirmed ← حجز وتوزيع، shipped ← فك حجز + بيع فعلي،
 *   cancelled (من confirmed/processing) ← فك حجز.
 */
class OrderService
{
    /** الانتقالات المسموحة بين حالات الطلب. */
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public function __construct(
        protected OrderAllocationService $allocationService,
        protected CartService $cartService,
        protected CouponService $couponService,
        protected SettingService $settings,
        protected InventoryService $inventoryService,
    ) {}

    /**
     * تحويل سلة إلى طلب جديد بحالة pending.
     *
     * @param  array{recipient_name?: string|null, recipient_phone?: string|null, recipient_email?: string|null, city_id?: int|null, shipping_address_line?: string|null, shipping_notes?: string|null, payment_method?: string|null, channel?: string}  $data
     */
    public function createFromCart(Cart $cart, array $data, ?Customer $customer = null): Order
    {
        return DB::transaction(function () use ($cart, $data, $customer): Order {
            $cart->loadMissing('items');
            if ($cart->items->isEmpty()) {
                throw new BusinessException('السلة فارغة؛ لا يمكن إنشاء طلب.');
            }

            $lines = $this->cartService->pricedLines($cart, $customer);
            foreach ($lines as $line) {
                if ($line['unit_price'] === null) {
                    throw new BusinessException("الصنف ({$line['variant']->sku}) غير مُسعَّر؛ لا يمكن إتمام الطلب.");
                }
            }

            // إعادة التحقق من التوفر لحظة الشراء (رصيد المتجر عبر الفروع النشطة)
            $this->cartService->assertLinesAvailable($lines);
            $totals = $this->cartService->totals($cart, $customer);
            $subtotal = $totals['subtotal'];
            $pricingDiscount = $totals['pricing_discount'];

            // الكوبون: إعادة تحقق صارمة عند الشراء + احتساب الخصم + حجز استخدام
            $couponDiscount = 0.0;
            $couponId = null;
            $couponCode = null;
            if ($cart->coupon_id !== null) {
                $coupon = $this->couponService->redeemableById((int) $cart->coupon_id, $totals['items_total']);
                $couponDiscount = $this->couponService->discountFor($coupon, $totals['items_total']);
                $couponId = $coupon->id;
                $couponCode = $coupon->code;
                $coupon->increment('used_count');
            }
            $discountAmount = round($pricingDiscount + $couponDiscount, 2);

            // الشحن من مدينة التوصيل
            $city = null;
            $shippingCost = 0.0;
            if (! empty($data['city_id'])) {
                $city = City::query()->where('is_active', true)->find((int) $data['city_id']);
                if ($city === null) {
                    throw new BusinessException('مدينة التوصيل غير متاحة.');
                }
                $shippingCost = (float) $city->shipping_cost;
            }

            // الضريبة من الإعدادات (مفعّلة وغير مشمولة في الأسعار فقط)
            $taxAmount = 0.0;
            $taxRate = 0.0;
            if (filter_var($this->settings->get('tax_enabled', false), FILTER_VALIDATE_BOOLEAN)
                && ! filter_var($this->settings->get('prices_include_tax', false), FILTER_VALIDATE_BOOLEAN)) {
                $taxRate = (float) $this->settings->get('default_tax_rate', 0);
                $taxAmount = round(($subtotal - $discountAmount) * $taxRate / 100, 2);
            }

            $grandTotal = round(($subtotal - $discountAmount) + $shippingCost + $taxAmount, 2);

            $order = Order::create([
                'order_number' => $this->settings->generateNextNumber('order'),
                'channel' => $data['channel'] ?? 'store',
                'customer_id' => $customer?->id,
                'guest_name' => $data['recipient_name'] ?? $customer?->name,
                'guest_phone' => $data['recipient_phone'] ?? $customer?->phone,
                'guest_email' => $data['recipient_email'] ?? $customer?->email,
                'city_id' => $city?->id,
                'shipping_address_line' => $data['shipping_address_line'] ?? null,
                'shipping_notes' => $data['shipping_notes'] ?? null,
                'status' => OrderStatus::Pending,
                'payment_method' => $data['payment_method'] ?? 'cod',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'shipping_cost' => $shippingCost,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
            ]);

            // بنود الطلب بلقطات الاسم والـ SKU والسعر + توزيع الضريبة تناسبيًا
            $remainingTax = $taxAmount;
            $lastIndex = count($lines) - 1;
            foreach ($lines as $i => $line) {
                $lineTax = $i === $lastIndex
                    ? $remainingTax
                    : round($taxAmount * ($line['line_total'] / max($totals['items_total'], 0.01)), 2);
                $remainingTax = round($remainingTax - $lineTax, 2);

                $order->items()->create([
                    'product_variant_id' => $line['variant']->id,
                    'product_name_snapshot' => $line['variant']->product?->name_ar ?? '',
                    'sku_snapshot' => $line['variant']->sku,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['line_discount'],
                    'tax_amount' => max($lineTax, 0),
                    'line_total' => $line['line_total'],
                ]);
            }

            // أول سجل حالة: إنشاء الطلب
            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'changed_by_type' => $customer?->getMorphClass(),
                'changed_by_id' => $customer?->getKey(),
                'notes' => 'تم إنشاء الطلب من السلة',
            ]);

            // إفراغ السلة بعد تحويلها لطلب
            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);

            return $order->fresh(['items', 'statusHistories']);
        });
    }

    public function changeStatus(Order $order, OrderStatus|string $toStatus, ?Model $actor = null, ?string $notes = null): Order
    {
        $to = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $to, $actor, $notes): Order {
            $from = $order->status;
            if ($from === $to) {
                return $order;
            }

            if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
                throw new BusinessException("انتقال حالة غير مسموح: من ({$from->value}) إلى ({$to->value}).");
            }

            $actorId = $this->inventoryActorId($actor);

            // آثار المخزون بحسب الانتقال
            if ($to === OrderStatus::Shipped) {
                $this->shipOrder($order, $actorId);
            } elseif ($to === OrderStatus::Cancelled
                && in_array($from, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                $this->releaseReservations($order, $actorId, 'إلغاء الطلب');
            }

            $order->update(['status' => $to]);

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => $notes,
            ]);

            if ($to === OrderStatus::Confirmed && $order->allocated_branch_id === null) {
                $this->allocationService->allocate($order, performedBy: $actorId);
            }

            return $order->fresh(['items', 'statusHistories']);
        });
    }

    /**
     * الشحن: لكل حركة حجز — فك الحجز من reserved وخصم فعلي sale من on_hand
     * على نفس صف الرصيد، وزيادة عدّاد مبيعات المنتج.
     */
    private function shipOrder(Order $order, ?int $actorId): void
    {
        $reservations = $this->reserveMovements($order);
        if ($reservations->isEmpty()) {
            throw new BusinessException('لا توجد حجوزات مخزون لهذا الطلب؛ لا يمكن الشحن قبل التأكيد والتوزيع.');
        }

        foreach ($reservations as $movement) {
            $base = [
                'product_variant_id' => $movement->product_variant_id,
                'branch_id' => $movement->branch_id,
                'location_id' => $movement->location_id,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
            ];
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $movement->quantity,
                'notes' => "فك حجز عند شحن الطلب {$order->order_number}",
            ]);
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::Sale,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1 * (float) $movement->quantity,
                'notes' => "بيع عند شحن الطلب {$order->order_number}",
            ]);
        }

        foreach ($order->items as $item) {
            $item->variant?->product?->increment('sales_count', (int) round((float) $item->qty));
        }
    }

    /** إلغاء طلب مُحجوز: فك كل حجوزاته فتعود الكميات متاحة للبيع. */
    private function releaseReservations(Order $order, ?int $actorId, string $reason): void
    {
        foreach ($this->reserveMovements($order) as $movement) {
            $this->inventoryService->recordMovement([
                'product_variant_id' => $movement->product_variant_id,
                'branch_id' => $movement->branch_id,
                'location_id' => $movement->location_id,
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $movement->quantity,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
                'notes' => "{$reason} {$order->order_number}",
            ]);
        }
    }

    /** حركات الحجز الأصلية للطلب من الدفتر (مصدر الحقيقة لمواضع الحجز ومواقعه). */
    private function reserveMovements(Order $order): Collection
    {
        return StockMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->where('type', StockMovementType::Reserve)
            ->orderBy('id')
            ->get();
    }

    /**
     * منفّذ حركات المخزون: الموظف الفاعل نفسه؛ وعند غيابه (عميل/نظام)
     * يُنسب التنفيذ لأول حساب Super Admin — الدفتر يتطلب موظفًا دائمًا.
     */
    private function inventoryActorId(?Model $actor): ?int
    {
        if ($actor instanceof User) {
            return (int) $actor->getKey();
        }

        $systemUserId = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))
            ->orderBy('id')
            ->value('id');

        return $systemUserId !== null ? (int) $systemUserId : (auth()->user() instanceof User ? (int) auth()->id() : null);
    }
}
