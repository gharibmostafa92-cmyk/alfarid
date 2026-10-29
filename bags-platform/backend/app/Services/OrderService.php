<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\OrderCreated;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles order placement, status transition lifecycle, and associated inventory effects.
 *
 * Coordinates cart checkout, atomic stock reservation, coupon redemption,
 * and ledger updates across order state transitions (confirmed, shipped, cancelled, returned).
 */
class OrderService
{
    /** Permitted state machine transitions for order statuses. */
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
        protected ShippingQuoteService $shippingQuote,
    ) {}

    /**
     * Converts a customer or guest cart into a pending order with reserved inventory.
     *
     * @param  array{recipient_name?: string|null, recipient_phone?: string|null, recipient_email?: string|null, city_id?: int|null, shipping_address_line?: string|null, shipping_notes?: string|null, payment_method?: string|null, channel?: string, idempotency_key?: string|null, expected_total?: float|int|string|null}  $data
     */
    public function createFromCart(Cart $cart, array $data, ?Customer $customer = null): Order
    {
        // Idempotency key scoped to authenticated customer or normalized guest phone to prevent replay attacks.
        $idempotencyKey = isset($data['idempotency_key']) && is_string($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? mb_substr($data['idempotency_key'], 0, 191)
            : null;

        $guestPhone = $data['recipient_phone'] ?? $customer?->phone;
        $guestPhoneNormalized = is_string($guestPhone) && trim($guestPhone) !== ''
            ? Customer::normalizePhone($guestPhone)
            : null;
        // Require at least one digit in guest phone to prevent empty string collisions in guest scope.
        if ($customer === null && $guestPhoneNormalized !== null
            && preg_match('/\d/', $guestPhoneNormalized) !== 1) {
            throw new BusinessException('رقم هاتف صالح مطلوب لإتمام الشراء كزائر.');
        }
        $idempotencyScope = $customer !== null
            ? 'customer:'.$customer->id
            : ($guestPhoneNormalized !== null ? 'guest:'.$guestPhoneNormalized : null);

        if ($idempotencyKey !== null && $idempotencyScope !== null) {
            $existing = Order::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('idempotency_scope', $idempotencyScope)
                ->first();
            if ($existing !== null) {
                return $existing->load('items');
            }
        }

        // H5: validation failures never consume the daily phone budget.
        $phoneDayKey = $customer === null && $guestPhoneNormalized !== null && preg_match('/\d/', $guestPhoneNormalized) === 1
            ? 'checkout-phone-day:'.hash('sha256', $guestPhoneNormalized)
            : null;
        if ($phoneDayKey !== null && RateLimiter::tooManyAttempts($phoneDayKey, (int) config('auth.customer_limits.checkout_phone_day', 20))) {
            throw new BusinessException('تجاوزت عدد الطلبات اليومية المسموحة لهذا الرقم؛ حاول غدًا.', [], 429);
        }

        $created = DB::transaction(function () use ($cart, $data, $customer, $idempotencyKey, $idempotencyScope, $guestPhoneNormalized, $phoneDayKey): Order {
            // Transaction-level advisory lock serializes concurrent checkouts with the same idempotency key.
            if ($idempotencyKey !== null && $idempotencyScope !== null) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['order-idem:'.$idempotencyScope.':'.$idempotencyKey]);
                $existing = Order::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('idempotency_scope', $idempotencyScope)
                    ->first();
                if ($existing !== null) {
                    return $existing->load('items');
                }
            }

            // Lock cart row and fetch fresh items so concurrent checkouts cannot double-process the same cart.
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $cart->load('items');
            if ($cart->items->isEmpty()) {
                throw new BusinessException('السلة فارغة؛ لا يمكن إنشاء طلب.');
            }

            foreach ($cart->items as $item) {
                $this->cartService->assertWholeQuantity((float) $item->qty);
            }
            $lines = $this->cartService->pricedLines($cart, $customer);
            foreach ($lines as $line) {
                if ($line['unit_price'] === null) {
                    throw new BusinessException("الصنف ({$line['variant']->sku}) غير مُسعَّر؛ لا يمكن إتمام الطلب.");
                }
                // Reject zero-value lines resulting from extreme rounding or micro-pricing anomalies.
                if ($line['line_total'] <= 0) {
                    throw new BusinessException("قيمة الصنف ({$line['variant']->sku}) صفر؛ راجع الكمية أو السعر.");
                }
            }

            // Enforce maximum quantity caps per line and pending orders cap to prevent inventory hoarding.
            $isWholesale = $this->isWholesaleApproved($customer);
            if (! $isWholesale) {
                $lineCap = $customer === null
                    ? (float) config('store.guest_max_line_qty', 10)
                    : (float) config('store.retail_max_line_qty', 50);
                if ($lineCap > 0) {
                    foreach ($lines as $line) {
                        if ((float) $line['qty'] > $lineCap) {
                            throw new BusinessException($customer === null
                                ? "سقف كمية البند الواحد للزوار ({$lineCap}) — سجّل الدخول للكميات الأكبر."
                                : "سقف كمية البند الواحد ({$lineCap}) — للكميات الأكبر تواصل مع مسار الجملة.");
                        }
                    }
                }
            }

            if ($customer === null) {
                $pendingCap = (int) config('store.guest_pending_orders_cap', 3);
                if ($pendingCap > 0 && $guestPhoneNormalized !== null) {
                    $pendingForPhone = Order::query()
                        ->whereNull('customer_id')
                        ->where('status', OrderStatus::Pending)
                        ->where('guest_phone_normalized', $guestPhoneNormalized)
                        ->count();
                    if ($pendingForPhone >= $pendingCap) {
                        throw new BusinessException('لديك طلبات معلّقة كثيرة على هذا الرقم؛ أكملها أو ألغِها قبل طلب جديد.');
                    }
                }
            } elseif (! $isWholesale) {
                $pendingCap = (int) config('store.customer_pending_orders_cap', 5);
                if ($pendingCap > 0) {
                    $pendingForCustomer = Order::query()
                        ->where('customer_id', $customer->id)
                        ->where('status', OrderStatus::Pending)
                        ->count();
                    if ($pendingForCustomer >= $pendingCap) {
                        throw new BusinessException('لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.');
                    }
                }
            }

            // Re-verify product availability and purchasability at checkout time.
            $this->cartService->assertLinesAvailable($lines);
            $this->cartService->assertLinesPurchasable($lines);
            $totals = $this->cartService->totals($cart, $customer, $lines);
            $subtotal = $totals['subtotal'];
            $pricingDiscount = $totals['pricing_discount'];

            // Strict coupon validation and rate-limiting at redemption time.
            $couponDiscount = 0.0;
            $couponId = null;
            $couponCode = null;
            $coupon = null;
            if ($cart->coupon_id !== null) {
                try {
                    $coupon = $this->couponService->redeemableById(
                        (int) $cart->coupon_id,
                        $totals['items_total'],
                        $customer,
                        $customer === null ? ($data['recipient_phone'] ?? null) : null,
                    );
                } catch (BusinessException $e) {
                    // Tag coupon invalidation so the front-end can prompt coupon removal.
                    throw new BusinessException($e->getMessage(), $e->errors, $e->status, 'coupon_invalid');
                }
                $this->couponService->enforceRateLimit($coupon, 'redeem');
                $couponDiscount = $this->couponService->discountFor($coupon, $totals['items_total']);
                $couponId = $coupon->id;
                $couponCode = $coupon->code;
            }
            $discountAmount = round($pricingDiscount + $couponDiscount, 2);

            // Calculate shipping quote and COD surcharge based on destination city and active rates.
            $city = null;
            $shippingCost = 0.0;
            $codFee = 0.0;
            if (! empty($data['city_id'])) {
                $city = City::query()->where('is_active', true)->find((int) $data['city_id']);
                if ($city === null) {
                    throw new BusinessException('مدينة التوصيل غير متاحة.');
                }
                $quote = $this->shippingQuote->quoteForCity(
                    $city->id,
                    $totals['items_total'] - $couponDiscount,
                    ($data['payment_method'] ?? 'cod') === 'cod',
                );
                $shippingCost = $quote['cost'];
                $codFee = $quote['cod_fee'];
            }
            $waivedShipping = 0.0;
            if ($coupon !== null && $coupon->type === 'free_shipping' && $shippingCost > 0) {
                $waivedShipping = $shippingCost;
                $shippingCost = 0.0;
            }

            // Apply sales tax from settings when enabled and not already included in catalog prices.
            $taxAmount = 0.0;
            $taxRate = 0.0;
            if ($this->settings->getBool('tax_enabled') && ! $this->settings->getBool('prices_include_tax')) {
                $taxRate = (float) $this->settings->get('default_tax_rate', 0);
                $taxAmount = round(($subtotal - $discountAmount) * $taxRate / 100, 2);
            }

            $grandTotal = round(($subtotal - $discountAmount) + $shippingCost + $codFee + $taxAmount, 2);

            // Reject checkout if grand total drifted since cart preview (e.g. price update or expired promo).
            if (isset($data['expected_total']) && is_numeric($data['expected_total'])
                && abs((float) $data['expected_total'] - $grandTotal) > 0.01) {
                throw new BusinessException(
                    'تغيّر إجمالي الطلب منذ عرضه. راجع السلة ثم أعد المحاولة.',
                    [], 422, 'price_changed',
                );
            }

            $order = Order::create([
                'order_number' => $this->settings->generateNextNumber('order'),
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $idempotencyScope,
                'channel' => $data['channel'] ?? 'store',
                'customer_id' => $customer?->id,
                'guest_name' => $data['recipient_name'] ?? $customer?->name,
                'guest_phone' => $data['recipient_phone'] ?? $customer?->phone,
                'guest_phone_normalized' => $guestPhoneNormalized,
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
                'cod_fee' => $codFee,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
            ]);

            // Distribute tax and coupon discounts across line items using the largest remainder method.
            $weights = array_map(fn (array $line): float => (float) $line['line_total'], $lines);
            $taxShares = $this->distributeLargestRemainder($taxAmount, $weights);
            $couponShares = $this->distributeLargestRemainder($couponDiscount, $weights);
            foreach ($lines as $i => $line) {
                $order->items()->create([
                    'product_variant_id' => $line['variant']->id,
                    'product_name_snapshot' => $line['variant']->product?->name_ar ?? '',
                    'sku_snapshot' => $line['variant']->sku,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => round($line['line_discount'] + $couponShares[$i], 2),
                    'tax_amount' => $taxShares[$i],
                    'line_total' => $line['line_total'],
                ]);
            }

            // Reserve stock immediately under pessimistic locks so the last unit cannot be oversold.
            $order->load('items');
            $this->allocationService->allocate($order, performedBy: $this->inventoryActorId($customer));
            $order->refresh();

            // Free shipping coupon without actual shipping cost does not consume user redemption limit.
            if ($coupon !== null
                && ! ($coupon->type === 'free_shipping' && $waivedShipping <= 0)) {
                $this->couponService->recordUsage(
                    $coupon,
                    $order,
                    $customer,
                    $coupon->type === 'free_shipping' ? $waivedShipping : $couponDiscount,
                );
            }

            if (($data['payment_method'] ?? 'cod') === 'wallet') {
                if ($customer === null || $customer->fresh()->status !== CustomerStatus::Approved) {
                    throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
                }
                app(WalletService::class)->record($customer->id, -$grandTotal, 'checkout_payment', 'order', $order->id);
                $order->update(['payment_status' => 'paid']);
            }

            // Automatically persist customer shipping address for streamlined future checkouts.
            if ($customer !== null && $city !== null && ! empty($data['shipping_address_line'])) {
                $line = mb_substr(trim((string) $data['shipping_address_line']), 0, 500);
                $addressQuery = $customer->addresses();
                if ($line !== '' && ! (clone $addressQuery)->where('city_id', $city->id)->where('address_line', $line)->exists()) {
                    $addressQuery->create([
                        'city_id' => $city->id,
                        'address_line' => $line,
                        'is_default' => ! (clone $addressQuery)->where('is_default', true)->exists(),
                    ]);
                }
            }

            // Record initial pending status transition in history log.
            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'changed_by_type' => $customer?->getMorphClass(),
                'changed_by_id' => $customer?->getKey(),
                'notes' => 'تم إنشاء الطلب من السلة',
            ]);

            // Remove only ordered items from cart to preserve items concurrently added in other tabs.
            $orderedItemIds = array_values(array_unique(array_map(
                fn (array $line): int => (int) $line['item_id'],
                $lines,
            )));
            if ($orderedItemIds !== []) {
                $cart->items()->whereIn('id', $orderedItemIds)->delete();
            }
            $cart->update(['coupon_id' => null]);

            $created = $order->fresh(['items', 'statusHistories']);
            OrderCreated::dispatch($created);

            if ($phoneDayKey !== null) {
                DB::afterCommit(fn () => RateLimiter::hit($phoneDayKey, 24 * 3600));
            }

            return $created;
        });

        return $created;
    }

    /**
     * Executes an order status transition and triggers corresponding inventory and payment side-effects.
     *
     * @param  array<int, OrderStatus>|null  $expectedFrom  Expected previous statuses to prevent race conditions.
     * @param  bool  $returnEffects  Whether to generate automatic stock and refund side effects for returns.
     * @param  bool  $allowNoticesLoss  Allows cancelling credit orders with approved wholesale payment notices.
     */
    public function changeStatus(Order $order, OrderStatus|string $toStatus, ?Model $actor = null, ?string $notes = null, ?array $expectedFrom = null, bool $returnEffects = true, bool $allowNoticesLoss = false): Order
    {
        $to = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $to, $actor, $notes, $expectedFrom, $returnEffects, $allowNoticesLoss): Order {
            // Lock order row to serialize status transitions and guard against stale client snapshots.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if ($expectedFrom !== null && ! in_array($from, $expectedFrom, true)) {
                throw new BusinessException("تغيّرت حالة الطلب أثناء العملية (أصبحت {$from->value})؛ أعد المحاولة.");
            }

            if ($from === $to) {
                return $order;
            }

            if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
                throw new BusinessException("انتقال حالة غير مسموح: من ({$from->value}) إلى ({$to->value}).");
            }

            $actorId = $this->inventoryActorId($actor);

            if ($to === OrderStatus::Returned && $returnEffects && $order->channel === 'pos') {
                throw new BusinessException('استخدم مرتجع POS لتسوية المخزون والنقدية معًا.');
            }

            if ($to === OrderStatus::Returned && $returnEffects
                && ReturnRequest::query()->where('order_id', $order->id)->whereNotIn('status', [
                    ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value,
                ])->exists()) {
                throw new BusinessException('للطلب مرتجعات قائمة؛ أكمل الاستلام والاسترداد من إدارة المرتجعات لمنع تكرار المخزون.');
            }

            // Prevent silent cancellation of credit orders that hold approved wholesale treasury notices.
            if ($to === OrderStatus::Cancelled && ! $allowNoticesLoss
                && $order->payment_method === 'credit'
                && \App\Models\WholesalePaymentNotice::query()->where('order_id', $order->id)
                    ->where('status', \App\Enums\WholesalePaymentNoticeStatus::Approved->value)->exists()) {
                throw new BusinessException('للطلب الآجل إشعارات سداد معتمدة مقيدة في الخزينة؛ سوِّها أولًا (رد أو تحويلها لائتمان حر) قبل الإلغاء.');
            }

            $extraNote = null;
            if ($to === OrderStatus::Shipped) {
                $this->shipOrder($order, $actorId);
            } elseif ($to === OrderStatus::Cancelled
                && in_array($from, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                // Stock is reserved from creation time, so release reservations on cancellation.
                $this->releaseReservations($order, $actorId, 'إلغاء الطلب');
            } elseif ($to === OrderStatus::Returned
                && in_array($from, [OrderStatus::Shipped, OrderStatus::Delivered], true)
                && $returnEffects) {
                // Reverse sales in stock ledger unless handled by the warehouse RMA intake process.
                if (! $this->returnSalesToStock($order, $actorId)) {
                    $extraNote = 'لا توجد حركات بيع مسجلة؛ يتطلب المرتجع استلامًا يدويًا في المستودع.';
                }
            }

            if ($to === OrderStatus::Cancelled) {
                // Restore coupon usage quota upon order cancellation.
                $this->reverseCouponUsage($order);
                if ($order->payment_status === 'paid') {
                    $order->update(['requires_refund' => true]);
                }
            }

            // Flag paid returned order for refund processing.
            if ($to === OrderStatus::Returned && $order->payment_status === 'paid' && $returnEffects) {
                $order->update(['requires_refund' => true]);
            }

            if ($order->payment_method === 'wallet' && $order->payment_status === 'paid'
                && ($to === OrderStatus::Cancelled || ($to === OrderStatus::Returned && $returnEffects))) {
                $payment = CustomerWalletTransaction::query()->where('reference_type', 'order')->where('reference_id', $order->id)
                    ->where('type', 'checkout_payment')->first();
                if ($payment !== null) {
                    app(WalletService::class)->record($payment->customer_id, -(float) $payment->amount,
                        'order_reversal', 'order', $order->id, $actorId);
                    $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
                }
            }

            $order->update(['status' => $to]);

            // Auto-settle COD payment status upon delivery.
            if ($to === OrderStatus::Delivered
                && $order->payment_method === 'cod'
                && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }

            // Truncate notes to fit the varchar(500) database column limit.
            $historyNotes = trim(($notes ?? '').($extraNote !== null ? ' — '.$extraNote : '')) ?: $notes;

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => $historyNotes !== null ? mb_substr($historyNotes, 0, 500) : null,
            ]);

            // Trigger item allocation if the order transitioned to confirmed without full allocation.
            if ($to === OrderStatus::Confirmed && ! $this->isAllocated($order)) {
                $this->allocationService->allocate($order, performedBy: $actorId);
            }

            return $order->fresh(['items', 'statusHistories']);
        });
    }

    /**
     * Checks whether all order items have been allocated to fulfillment branches.
     */
    private function isAllocated(Order $order): bool
    {
        if ($order->allocated_branch_id !== null) {
            return true;
        }

        $distribution = $order->items()
            ->selectRaw('COUNT(*) AS total, COUNT(allocated_branch_id) AS allocated')
            ->first();

        if ($distribution === null || (int) $distribution->total === 0) {
            return false;
        }

        return (int) $distribution->total === (int) $distribution->allocated;
    }

    /**
     * Checks if customer has approved wholesale/distributor status exempt from retail limits.
     */
    private function isWholesaleApproved(?Customer $customer): bool
    {
        return $customer !== null
            && $customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true);
    }

    /**
     * Distributes a total currency amount across weights using Hare-Niemeyer (largest remainder) algorithm.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function distributeLargestRemainder(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }
        $totalCents = (int) round($total * 100);
        $weightSum = array_sum($weights);
        if ($totalCents <= 0 || $weightSum <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $floorCents = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $i => $weight) {
            $exact = $totalCents * ((float) $weight / $weightSum);
            $floor = (int) floor($exact);
            $floorCents[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $assigned += $floor;
        }

        $left = $totalCents - $assigned;
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $floorCents[$i]++;
            $left--;
        }

        ksort($floorCents);

        return array_map(fn (int $cents): float => $cents / 100, $floorCents);
    }

    /**
     * Releases reservations and records sale stock movements for all items in the order.
     */
    private function shipOrder(Order $order, ?int $actorId): void
    {
        $net = $this->netReservations($order);
        if ($net->isEmpty()) {
            throw new BusinessException('لا توجد حجوزات مخزون لهذا الطلب؛ لا يمكن الشحن قبل التأكيد والتوزيع.');
        }

        $levels = $this->inventoryService->lockLevels($net->map(fn (array $row): array => [
            'product_variant_id' => $row['product_variant_id'],
            'branch_id' => $row['branch_id'],
            'location_id' => $row['location_id'],
        ])->all());

        $costs = ProductVariant::withTrashed()->whereIn('id', $net->pluck('product_variant_id'))->pluck('cost_price', 'id');
        foreach ($net as $row) {
            $base = [
                'product_variant_id' => $row['product_variant_id'],
                'branch_id' => $row['branch_id'],
                'location_id' => $row['location_id'],
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
            ];
            $level = $levels->get($this->inventoryService->levelKey($row));
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $row['net_qty'],
                'notes' => "فك حجز عند شحن الطلب {$order->order_number}",
            ], $level);
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::Sale,
                'unit_cost' => $costs[$row['product_variant_id']] ?? null,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1 * (float) $row['net_qty'],
                'notes' => "بيع عند شحن الطلب {$order->order_number}",
            ], $level);
        }

        foreach ($order->items as $item) {
            $item->variant?->product?->increment('sales_count', (int) round((float) $item->qty));
        }
    }

    /**
     * Releases active stock reservations for cancelled orders back into available inventory.
     */
    private function releaseReservations(Order $order, ?int $actorId, string $reason): void
    {
        $net = $this->netReservations($order);
        if ($net->isEmpty()) {
            return;
        }

        $levels = $this->inventoryService->lockLevels($net->map(fn (array $row): array => [
            'product_variant_id' => $row['product_variant_id'],
            'branch_id' => $row['branch_id'],
            'location_id' => $row['location_id'],
        ])->all());

        foreach ($net as $row) {
            $this->inventoryService->recordMovement([
                'product_variant_id' => $row['product_variant_id'],
                'branch_id' => $row['branch_id'],
                'location_id' => $row['location_id'],
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $row['net_qty'],
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
                'notes' => "{$reason} {$order->order_number}",
            ], $levels->get($this->inventoryService->levelKey($row)));
        }
    }

    /**
     * Restores delivered/shipped goods to on-hand inventory via SaleReturn stock movements.
     */
    private function returnSalesToStock(Order $order, ?int $actorId): bool
    {
        $net = StockMovement::query()
            ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
            ->where('reference_id', $order->id)
            ->whereIn('type', [StockMovementType::Sale->value, StockMovementType::SaleReturn->value])
            ->groupBy('product_variant_id', 'branch_id', 'location_id')
            ->selectRaw('product_variant_id, branch_id, location_id, SUM(quantity) AS net_qty, MAX(unit_cost) FILTER (WHERE type = \'sale\') AS sale_unit_cost')
            ->get()
            // Negative net represents un-returned sales (Sale is negative, SaleReturn is positive).
            ->map(fn ($row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'branch_id' => (int) $row->branch_id,
                'location_id' => $row->location_id === null ? null : (int) $row->location_id,
                'net_qty' => (float) $row->net_qty,
                'sale_unit_cost' => $row->sale_unit_cost,
            ])
            ->filter(fn (array $row): bool => $row['net_qty'] < -1e-9)
            ->sortBy(fn (array $row): string => $row['branch_id'].':'.$row['location_id'].':'.$row['product_variant_id'])
            ->values();

        if ($net->isEmpty()) {
            return false;
        }

        $levels = $this->inventoryService->lockLevels($net->map(fn (array $row): array => [
            'product_variant_id' => $row['product_variant_id'],
            'branch_id' => $row['branch_id'],
            'location_id' => $row['location_id'],
        ])->all());

        foreach ($net as $row) {
            $this->inventoryService->recordMovement([
                'product_variant_id' => $row['product_variant_id'],
                'branch_id' => $row['branch_id'],
                'location_id' => $row['location_id'],
                'type' => StockMovementType::SaleReturn,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1 * (float) $row['net_qty'],
                'unit_cost' => $row['sale_unit_cost'],
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
                'notes' => "مرتجع مبيعات للطلب {$order->order_number}",
            ], $levels->get($this->inventoryService->levelKey($row)));
        }

        foreach ($order->items as $item) {
            if ($item->variant?->product_id) {
                Product::query()->whereKey($item->variant->product_id)
                    ->update(['sales_count' => DB::raw('GREATEST(sales_count - '.(int) round((float) $item->qty).', 0)')]);
            }
        }

        return true;
    }

    /**
     * Computes net active stock reservations per variant/location for an order.
     *
     * @return Collection<int, array{product_variant_id: int, branch_id: int, location_id: int|null, net_qty: float}>
     */
    private function netReservations(Order $order): Collection
    {
        return StockMovement::query()
            ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
            ->where('reference_id', $order->id)
            ->whereIn('type', [StockMovementType::Reserve->value, StockMovementType::ReleaseReserve->value])
            ->groupBy('product_variant_id', 'branch_id', 'location_id')
            ->selectRaw('product_variant_id, branch_id, location_id, SUM(quantity) AS net_qty')
            ->get()
            ->map(fn ($row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'branch_id' => (int) $row->branch_id,
                'location_id' => $row->location_id === null ? null : (int) $row->location_id,
                'net_qty' => (float) $row->net_qty,
            ])
            ->filter(fn (array $row): bool => $row['net_qty'] > 1e-9)
            ->sortBy(fn (array $row): string => $row['branch_id'].':'.$row['location_id'].':'.$row['product_variant_id'])
            ->values();
    }

    /**
     * Reverses recorded coupon usage and decrements used_count on order cancellation.
     */
    private function reverseCouponUsage(Order $order): void
    {
        foreach (CouponUsage::query()->where('order_id', $order->id)->get() as $usage) {
            Coupon::query()->whereKey($usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            $usage->delete();
        }
    }

    /**
     * Retrieves original stock reserve movements associated with an order.
     */
    private function reserveMovements(Order $order): Collection
    {
        return StockMovement::query()
            ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
            ->where('reference_id', $order->id)
            ->where('type', StockMovementType::Reserve)
            ->orderBy('id')
            ->get();
    }

    // Instance-level cache avoids cross-test/Octane bleed of static properties.
    private ?int $systemActorId = null;

    private bool $systemActorResolved = false;

    /**
     * Resolves the user identity responsible for stock ledger entries.
     *
     * Falls back to dedicated system user for automated actions (webhooks, guest checkout).
     */
    private function inventoryActorId(?Model $actor): ?int
    {
        if ($actor instanceof User) {
            return (int) $actor->getKey();
        }

        if (! $this->systemActorResolved) {
            $this->systemActorResolved = true;
            $systemId = User::query()
                ->where('is_system', true)
                ->value('id');
            $this->systemActorId = $systemId !== null ? (int) $systemId : null;
        }
        if ($this->systemActorId !== null) {
            return $this->systemActorId;
        }

        throw new \LogicException('The System audit identity is missing; run the system-user migration before processing automated order effects.');
    }
}
