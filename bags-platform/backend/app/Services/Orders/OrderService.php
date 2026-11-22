<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Events\Orders\OrderCreated;
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
use App\Models\WholesalePaymentNotice;
use App\Services\CustomerEmail\CustomerEmailOutbox;
use App\Services\Finance\WalletService;
use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CouponService;
use App\Services\Shared\ProportionalDistribution;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use App\Support\StorefrontLegalPolicyVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    public function checkoutDiscount(float $subtotal, float $pricingDiscount, float $couponDiscount): float
    {
        return BigDecimal::min(
            BigDecimal::of((string) $subtotal),
            BigDecimal::of((string) $pricingDiscount)->plus((string) $couponDiscount),
        )->toScale(2, RoundingMode::HalfUp)->toFloat();
    }

    /**
     * Converts a customer or guest cart into a pending order with reserved inventory.
     *
     * @param  array{recipient_name?: string|null, recipient_phone?: string|null, recipient_email?: string|null, city_id?: int|null, shipping_address_line?: string|null, shipping_notes?: string|null, payment_method?: string|null, channel?: string, idempotency_key?: string|null, expected_total?: float|int|string|null, legal_policy_version?: string|null}  $data
     */
    public function createFromCart(Cart $cart, array $data, ?Customer $customer = null): Order
    {
        if (! $cart->exists) {
            throw new BusinessException('السلة فارغة؛ لا يمكن إنشاء طلب.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($data);
        $guestPhoneNormalized = $this->resolveGuestPhoneNormalized($data, $customer);
        $idempotencyScope = $this->buildIdempotencyScope($customer, $guestPhoneNormalized);

        $replay = $this->findMatchingIdempotentOrder($idempotencyKey, $idempotencyScope, $data, $cart, $customer);
        if ($replay !== null) {
            return $replay;
        }

        $phoneDayKey = $this->enforceGuestPhoneDailyLimit($customer, $guestPhoneNormalized);
        $this->enforceCartCouponRateLimit($cart);

        return DB::transaction(function () use (
            $cart,
            $data,
            $customer,
            $idempotencyKey,
            $idempotencyScope,
            $guestPhoneNormalized,
            $phoneDayKey,
        ): Order {
            // Transaction-level advisory lock serializes concurrent checkouts with the same idempotency key.
            if ($idempotencyKey !== null && $idempotencyScope !== null) {
                DB::statement('SELECT pg_advisory_xact_lock(103, hashtext(?))', ['order-idem:'.$idempotencyScope.':'.$idempotencyKey]);
                $lockedReplay = $this->findMatchingIdempotentOrder($idempotencyKey, $idempotencyScope, $data, $cart, $customer);
                if ($lockedReplay !== null) {
                    return $lockedReplay;
                }
            }

            $this->assertStorefrontLegalConsent($data);

            $lockedCart = $this->lockCartWithItems($cart);
            $lines = $this->loadValidatedPricedLines($lockedCart, $customer);
            $this->assertPendingOrderCap($customer, $guestPhoneNormalized);

            $totals = $this->cartService->totals($lockedCart, $customer, $lines);
            $subtotal = $totals['subtotal'];
            $pricingDiscount = $totals['pricing_discount'];

            [$coupon, $couponDiscount] = $this->resolveCheckoutCoupon(
                $lockedCart,
                $totals['items_total'],
                $customer,
                $data['recipient_phone'] ?? null,
            );
            $discountAmount = $this->checkoutDiscount($subtotal, $pricingDiscount, $couponDiscount);

            [$city, $shippingCost, $codFee, $waivedShipping] = $this->resolveShippingAndCod(
                $data,
                $totals['items_total'],
                $couponDiscount,
                $coupon,
            );

            // Apply sales tax and derive the grand total with the same decimal math the
            // preview endpoint uses, so expected_total can never drift by a cent.
            $charges = $this->calculateCharges($subtotal, $discountAmount, $shippingCost, $codFee);
            $taxAmount = $charges['tax'];
            $grandTotal = $charges['grand_total'];

            $this->assertCreditLimitAvailable($data, $customer, $grandTotal);
            $this->assertExpectedTotalUnchanged($data, $grandTotal);

            $order = $this->createOrderRecord(
                $data,
                $customer,
                $idempotencyKey,
                $idempotencyScope,
                $guestPhoneNormalized,
                $city,
                [
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'coupon_id' => $coupon?->id,
                    'coupon_code' => $coupon?->code,
                    'shipping_cost' => $shippingCost,
                    'cod_fee' => $codFee,
                    'tax_amount' => $taxAmount,
                    'grand_total' => $grandTotal,
                ],
            );

            $this->createOrderLineItems($order, $lines, $taxAmount, $discountAmount, $pricingDiscount);

            // Reserve stock immediately under pessimistic locks so the last unit cannot be oversold.
            $order->load('items');
            $this->allocationService->allocate($order, performedBy: $this->inventoryActorId($customer));
            $order->refresh();

            $this->processWalletCheckoutPayment($order, $customer, $grandTotal, $data);
            $this->persistCustomerShippingAddress($customer, $city, $data);
            $this->recordInitialStatusHistory($order, $customer);
            $this->clearOrderedCartItems($lockedCart, $lines);

            $created = $order->fresh(['items', 'statusHistories']);

            $this->recordCheckoutCouponUsage($coupon, $order, $customer, $waivedShipping, $couponDiscount);
            $this->dispatchOrderCreatedSideEffects($created, $phoneDayKey);

            return $created;
        });
    }

    /**
     * Shared money math for checkout previews and order placement. Net merchandise
     * is floored at zero before tax, and every step rounds exactly once.
     *
     * @return array{net: float, tax: float, grand_total: float}
     */
    public function calculateCharges(float $subtotal, float $discountAmount, float $shippingCost, float $codFee): array
    {
        $net = BigDecimal::of((string) $subtotal)->minus((string) $discountAmount);
        if ($net->isNegative()) {
            $net = BigDecimal::zero();
        }
        $net = $net->toScale(2, RoundingMode::HalfUp);

        $tax = BigDecimal::zero();
        if ($this->settings->getBool('tax_enabled') && ! $this->settings->getBool('prices_include_tax')) {
            $rate = (string) (float) $this->settings->get('default_tax_rate', 0);
            $tax = $net->multipliedBy($rate)->dividedBy('100', 2, RoundingMode::HalfUp);
        }

        $grand = $net
            ->plus(BigDecimal::of((string) $shippingCost)->toScale(2, RoundingMode::HalfUp))
            ->plus(BigDecimal::of((string) $codFee)->toScale(2, RoundingMode::HalfUp))
            ->plus($tax)
            ->toScale(2, RoundingMode::HalfUp);

        return ['net' => $net->toFloat(), 'tax' => $tax->toFloat(), 'grand_total' => $grand->toFloat()];
    }

    /**
     * Executes an order status transition and triggers corresponding inventory and payment side-effects.
     *
     * @param  array<int, OrderStatus>|null  $expectedFrom  Expected previous statuses to prevent race conditions.
     * @param  bool  $returnEffects  Whether to generate automatic stock and refund side effects for returns.
     * @param  bool  $allowNoticesLoss  Allows cancelling credit orders with approved wholesale payment notices.
     */
    public function changeStatus(
        Order $order,
        OrderStatus|string $toStatus,
        ?Model $actor = null,
        ?string $notes = null,
        ?array $expectedFrom = null,
        bool $returnEffects = true,
        bool $allowNoticesLoss = false,
    ): Order {
        $to = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $to, $actor, $notes, $expectedFrom, $returnEffects, $allowNoticesLoss): Order {
            $order = $this->lockOrderForStatusChange($order);
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

            $this->guardStatusTransition($order, $to, $returnEffects, $allowNoticesLoss);
            $extraNote = $this->applyTransitionInventoryEffects($order, $from, $to, $actorId, $returnEffects);
            $this->handleCancellationAndReturnRefunds($order, $to, $actorId, $returnEffects);
            $this->applyStatusColumnUpdates($order, $to);
            $this->settleCodPaymentOnDelivery($order, $to);
            $this->recordStatusHistory($order, $from, $to, $actor, $notes, $extraNote);
            $this->ensureConfirmedOrderAllocated($order, $to, $actorId);

            return $order->fresh(['items', 'statusHistories']);
        });
    }

    private function normalizeIdempotencyKey(array $data): ?string
    {
        if (! isset($data['idempotency_key']) || ! is_string($data['idempotency_key']) || $data['idempotency_key'] === '') {
            return null;
        }

        return mb_substr($data['idempotency_key'], 0, 191);
    }

    private function resolveGuestPhoneNormalized(array $data, ?Customer $customer): ?string
    {
        $guestPhone = $data['recipient_phone'] ?? $customer?->phone;
        $normalized = is_string($guestPhone) && trim($guestPhone) !== ''
            ? Customer::normalizePhone($guestPhone)
            : null;

        // Require at least one digit in guest phone to prevent empty string collisions in guest scope.
        if ($customer === null && $normalized !== null && preg_match('/\d/', $normalized) !== 1) {
            throw new BusinessException('رقم هاتف صالح مطلوب لإتمام الشراء كزائر.');
        }

        return $normalized;
    }

    private function buildIdempotencyScope(?Customer $customer, ?string $guestPhoneNormalized): ?string
    {
        if ($customer !== null) {
            return 'customer:'.$customer->id;
        }

        return $guestPhoneNormalized !== null ? 'guest:'.$guestPhoneNormalized : null;
    }

    private function findMatchingIdempotentOrder(
        ?string $idempotencyKey,
        ?string $idempotencyScope,
        array $data,
        Cart $cart,
        ?Customer $customer,
    ): ?Order {
        if ($idempotencyKey === null || $idempotencyScope === null) {
            return null;
        }

        $existing = Order::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('idempotency_scope', $idempotencyScope)
            ->first();

        return $existing !== null
            ? $this->assertMatchingIdempotentReplay($existing, $data, $cart, $customer)
            : null;
    }

    /**
     * Replays an idempotent checkout only when the new request still describes the stored order.
     */
    private function assertMatchingIdempotentReplay(Order $existing, array $data, Cart $cart, ?Customer $customer): Order
    {
        $storedPhone = $existing->guest_phone_normalized ?? Customer::normalizePhone((string) $existing->guest_phone);
        $mismatched = ($data['payment_method'] ?? 'cod') !== $existing->payment_method
            || ($data['channel'] ?? 'store') !== $existing->channel
            || (int) ($data['city_id'] ?? 0) !== (int) $existing->city_id
            || trim((string) ($data['shipping_address_line'] ?? '')) !== trim((string) $existing->shipping_address_line)
            || trim((string) ($data['shipping_notes'] ?? '')) !== trim((string) $existing->shipping_notes)
            || trim((string) ($data['recipient_name'] ?? $customer?->name)) !== trim((string) $existing->guest_name)
            || trim((string) ($data['recipient_email'] ?? $customer?->email)) !== trim((string) $existing->guest_email)
            || Customer::normalizePhone((string) ($data['recipient_phone'] ?? $customer?->phone)) !== $storedPhone
            || ($data['legal_policy_version'] ?? null) !== $existing->legal_policy_version
            || $this->expectedTotalChanged($data, (float) $existing->grand_total);

        $existing->loadMissing('items');
        if (! isset($data['expected_total'])) {
            $cart->loadMissing('items');
            if ($cart->items->isNotEmpty()) {
                $signature = static fn ($items) => $items->map(fn ($item) => $item->product_variant_id.':'.(int) $item->qty)
                    ->sort()->values()->all();
                $mismatched = $mismatched || $signature($cart->items) !== $signature($existing->items);
            }
        }

        if ($mismatched) {
            throw new BusinessException(
                'هذا المفتاح مستخدم لطلب ببيانات مختلفة.',
                [],
                409,
                'idempotency_conflict',
            );
        }

        return $existing;
    }

    /**
     * Enforces daily checkout quota per phone number without deducting budget on validation errors.
     */
    private function enforceGuestPhoneDailyLimit(?Customer $customer, ?string $guestPhoneNormalized): ?string
    {
        if ($customer !== null || $guestPhoneNormalized === null || preg_match('/\d/', $guestPhoneNormalized) !== 1) {
            return null;
        }

        $phoneDayKey = 'checkout-phone-day:'.hash('sha256', $guestPhoneNormalized);
        if (RateLimiter::tooManyAttempts($phoneDayKey, (int) config('auth.customer_limits.checkout_phone_day', 20))) {
            throw new BusinessException('تجاوزت عدد الطلبات اليومية المسموحة لهذا الرقم؛ حاول غدًا.', [], 429);
        }

        return $phoneDayKey;
    }

    private function enforceCartCouponRateLimit(Cart $cart): void
    {
        // M3: redeem rate limiting must run before the transaction. A database
        // cache store takes a row lock on increment, which would otherwise be
        // held for the whole checkout and serialize every coupon redemption.
        if ($cart->coupon_id === null) {
            return;
        }

        $couponForLimit = Coupon::query()->find($cart->coupon_id);
        if ($couponForLimit === null) {
            return;
        }

        try {
            $this->couponService->enforceRateLimit($couponForLimit, 'redeem');
        } catch (BusinessException $e) {
            throw new BusinessException($e->getMessage(), $e->errors, $e->status, 'coupon_invalid');
        }
    }

    private function assertStorefrontLegalConsent(array $data): void
    {
        if (($data['channel'] ?? 'store') === 'store'
            && ($data['legal_policy_version'] ?? null) !== StorefrontLegalPolicyVersion::CURRENT) {
            throw new BusinessException(
                'يرجى مراجعة الشروط والأحكام وقبول النسخة الحالية قبل إتمام الطلب.',
                [],
                422,
                'legal_consent_required',
            );
        }
    }

    private function lockCartWithItems(Cart $cart): Cart
    {
        // Lock cart row and fetch fresh items so concurrent checkouts cannot double-process the same cart.
        $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
        $locked->load('items');
        if ($locked->items->isEmpty()) {
            throw new BusinessException('السلة فارغة؛ لا يمكن إنشاء طلب.');
        }

        return $locked;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadValidatedPricedLines(Cart $cart, ?Customer $customer): array
    {
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
            $this->cartService->assertLineQty($customer, (float) $line['qty']);
        }

        // Re-verify product availability and purchasability at checkout time.
        $this->cartService->assertLinesAvailable($lines);
        $this->cartService->assertLinesPurchasable($lines);

        return $lines;
    }

    private function assertPendingOrderCap(?Customer $customer, ?string $guestPhoneNormalized): void
    {
        if ($customer === null) {
            $pendingCap = (int) config('store.guest_pending_orders_cap', 3);
            if ($pendingCap <= 0 || $guestPhoneNormalized === null) {
                return;
            }

            DB::statement('SELECT pg_advisory_xact_lock(103, hashtext(?))', ['pending-guest:'.$guestPhoneNormalized]);
            $pendingCount = Order::query()
                ->whereNull('customer_id')
                ->where('status', OrderStatus::Pending)
                ->where('guest_phone_normalized', $guestPhoneNormalized)
                ->count();
            if ($pendingCount >= $pendingCap) {
                throw new BusinessException('لديك طلبات معلّقة كثيرة على هذا الرقم؛ أكملها أو ألغِها قبل طلب جديد.');
            }

            return;
        }

        if ($this->isWholesaleApproved($customer)) {
            return;
        }

        $pendingCap = (int) config('store.customer_pending_orders_cap', 5);
        if ($pendingCap <= 0) {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(103, hashtext(?))', ['pending-customer:'.$customer->id]);
        $pendingCount = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', OrderStatus::Pending)
            ->count();
        if ($pendingCount >= $pendingCap) {
            throw new BusinessException('لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.');
        }
    }

    /**
     * @return array{0: Coupon|null, 1: float}
     */
    private function resolveCheckoutCoupon(Cart $cart, float $itemsTotal, ?Customer $customer, ?string $recipientPhone): array
    {
        if ($cart->coupon_id === null) {
            return [null, 0.0];
        }

        try {
            $coupon = $this->couponService->redeemableById(
                (int) $cart->coupon_id,
                $itemsTotal,
                $customer,
                $customer === null ? $recipientPhone : null,
                true,
            );
        } catch (BusinessException $e) {
            // Tag coupon invalidation so the front-end can prompt coupon removal.
            throw new BusinessException($e->getMessage(), $e->errors, $e->status, 'coupon_invalid');
        }

        return [$coupon, $this->couponService->discountFor($coupon, $itemsTotal)];
    }

    /**
     * @return array{0: City|null, 1: float, 2: float, 3: float}
     */
    private function resolveShippingAndCod(array $data, float $itemsTotal, float $couponDiscount, ?Coupon $coupon): array
    {
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
                max($itemsTotal - $couponDiscount, 0.0),
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

        return [$city, $shippingCost, $codFee, $waivedShipping];
    }

    private function assertCreditLimitAvailable(array $data, ?Customer $customer, float $grandTotal): void
    {
        if (($data['payment_method'] ?? null) !== 'credit' || $customer === null) {
            return;
        }

        Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
        $outstanding = app(WholesalePaymentNoticeService::class)->outstandingBalance($customer->fresh());
        if ($outstanding + $grandTotal > (float) $customer->credit_limit) {
            throw new BusinessException(
                'إجمالي الطلب يتجاوز حد الائتمان المتاح.',
                [],
                422,
                'credit_limit_exceeded',
            );
        }
    }

    private function assertExpectedTotalUnchanged(array $data, float $grandTotal): void
    {
        // Reject checkout if grand total drifted since cart preview (e.g. price update or expired promo).
        if ($this->expectedTotalChanged($data, $grandTotal)) {
            throw new BusinessException(
                'تغيّر إجمالي الطلب منذ عرضه. راجع السلة ثم أعد المحاولة.',
                [],
                422,
                'price_changed',
            );
        }
    }

    private function expectedTotalChanged(array $data, float $actualTotal): bool
    {
        if (! isset($data['expected_total']) || ! is_numeric($data['expected_total'])) {
            return false;
        }
        $expected = (float) $data['expected_total'];
        if (! is_finite($expected)) {
            return true;
        }

        return (int) round($expected * 100) !== (int) round($actualTotal * 100);
    }

    /**
     * @param  array{subtotal: float, discount_amount: float, coupon_id: int|null, coupon_code: string|null, shipping_cost: float, cod_fee: float, tax_amount: float, grand_total: float}  $pricing
     */
    private function createOrderRecord(
        array $data,
        ?Customer $customer,
        ?string $idempotencyKey,
        ?string $idempotencyScope,
        ?string $guestPhoneNormalized,
        ?City $city,
        array $pricing,
    ): Order {
        return Order::create([
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
            'subtotal' => $pricing['subtotal'],
            'discount_amount' => $pricing['discount_amount'],
            'coupon_id' => $pricing['coupon_id'],
            'coupon_code' => $pricing['coupon_code'],
            'shipping_cost' => $pricing['shipping_cost'],
            'cod_fee' => $pricing['cod_fee'],
            'tax_amount' => $pricing['tax_amount'],
            'grand_total' => $pricing['grand_total'],
            'legal_policy_version' => $data['legal_policy_version'] ?? null,
            'legal_accepted_at' => isset($data['legal_policy_version']) ? now() : null,
        ]);
    }

    /**
     * Distributes tax and effective coupon discounts across line items using the largest remainder method.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createOrderLineItems(Order $order, array $lines, float $taxAmount, float $discountAmount, float $pricingDiscount): void
    {
        $effectiveCouponDiscount = max(0.0, round($discountAmount - $pricingDiscount, 2));
        $weights = array_map(fn (array $line): float => (float) $line['line_total'], $lines);
        $taxShares = ProportionalDistribution::distribute($taxAmount, $weights);
        $couponShares = ProportionalDistribution::distribute($effectiveCouponDiscount, $weights);

        foreach ($lines as $i => $line) {
            $order->items()->create([
                'product_variant_id' => $line['variant']->id,
                'product_name_snapshot' => $line['variant']->product?->name_ar ?? '',
                'sku_snapshot' => $line['variant']->sku,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount_amount' => round($line['line_discount'] + $couponShares[$i], 2),
                'coupon_discount_amount' => $couponShares[$i],
                'tax_amount' => $taxShares[$i],
                'line_total' => $line['line_total'],
            ]);
        }
    }

    private function processWalletCheckoutPayment(Order $order, ?Customer $customer, float $grandTotal, array $data): void
    {
        if (($data['payment_method'] ?? 'cod') !== 'wallet') {
            return;
        }

        if ($customer === null || $customer->fresh()->status !== CustomerStatus::Approved) {
            throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
        }

        if ($grandTotal > 0) {
            app(WalletService::class)->record($customer->id, -$grandTotal, 'checkout_payment', 'order', $order->id);
        }
        $order->update(['payment_status' => 'paid']);
    }

    private function persistCustomerShippingAddress(?Customer $customer, ?City $city, array $data): void
    {
        if ($customer === null || $city === null || empty($data['shipping_address_line'])) {
            return;
        }

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

    private function recordInitialStatusHistory(Order $order, ?Customer $customer): void
    {
        $order->statusHistories()->create([
            'from_status' => null,
            'to_status' => OrderStatus::Pending,
            'changed_by_type' => $customer?->getMorphClass(),
            'changed_by_id' => $customer?->getKey(),
            'notes' => 'تم إنشاء الطلب من السلة',
        ]);
    }

    /**
     * Removes only ordered items from cart to preserve items concurrently added in other tabs.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function clearOrderedCartItems(Cart $cart, array $lines): void
    {
        $orderedItemIds = array_values(array_unique(array_map(
            fn (array $line): int => (int) $line['item_id'],
            $lines,
        )));
        if ($orderedItemIds !== []) {
            $cart->items()->whereIn('id', $orderedItemIds)->delete();
        }
        $cart->update(['coupon_id' => null]);
    }

    private function recordCheckoutCouponUsage(
        ?Coupon $coupon,
        Order $order,
        ?Customer $customer,
        float $waivedShipping,
        float $couponDiscount,
    ): void {
        // Keep the coupon counter lock until commit for as little time as possible.
        // Free shipping without a shipping fee does not consume a redemption.
        if ($coupon === null || ($coupon->type === 'free_shipping' && $waivedShipping <= 0)) {
            return;
        }

        try {
            $this->couponService->recordUsage(
                $coupon,
                $order,
                $customer,
                $coupon->type === 'free_shipping' ? $waivedShipping : $couponDiscount,
            );
        } catch (BusinessException $e) {
            throw new BusinessException($e->getMessage(), $e->errors, $e->status, 'coupon_invalid');
        }
    }

    private function dispatchOrderCreatedSideEffects(Order $created, ?string $phoneDayKey): void
    {
        if (in_array($created->channel, ['store', 'wholesale'], true)) {
            app(CustomerEmailOutbox::class)->orderReceived($created);
        }

        OrderCreated::dispatch($created);

        if ($phoneDayKey !== null) {
            DB::afterCommit(fn () => RateLimiter::hit($phoneDayKey, 24 * 3600));
        }
    }

    private function lockOrderForStatusChange(Order $order): Order
    {
        // Preserve canonical Customer -> Order lock hierarchy for credit orders.
        if ($order->customer_id !== null && $order->payment_method === 'credit') {
            Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
        }

        // Lock order row to serialize status transitions and guard against stale client snapshots.
        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    private function guardStatusTransition(Order $order, OrderStatus $to, bool $returnEffects, bool $allowNoticesLoss): void
    {
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
            && WholesalePaymentNotice::query()->where('order_id', $order->id)
                ->where('status', WholesalePaymentNoticeStatus::Approved->value)->exists()) {
            throw new BusinessException('للطلب الآجل إشعارات سداد معتمدة مقيدة في الخزينة؛ سوِّها أولًا (رد أو تحويلها لائتمان حر) قبل الإلغاء.');
        }
    }

    private function applyTransitionInventoryEffects(Order $order, OrderStatus $from, OrderStatus $to, ?int $actorId, bool $returnEffects): ?string
    {
        if ($to === OrderStatus::Shipped) {
            $this->shipOrder($order, $actorId);

            return null;
        }

        if ($to === OrderStatus::Cancelled
            && in_array($from, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            // Stock is reserved from creation time, so release reservations on cancellation.
            $this->releaseReservations($order, $actorId, 'إلغاء الطلب');

            return null;
        }

        if ($to === OrderStatus::Returned
            && in_array($from, [OrderStatus::Shipped, OrderStatus::Delivered], true)
            && $returnEffects) {
            // Reverse sales in stock ledger unless handled by the warehouse RMA intake process.
            if (! $this->returnSalesToStock($order, $actorId)) {
                return 'لا توجد حركات بيع مسجلة؛ يتطلب المرتجع استلامًا يدويًا في المستودع.';
            }
        }

        return null;
    }

    private function handleCancellationAndReturnRefunds(Order $order, OrderStatus $to, ?int $actorId, bool $returnEffects): void
    {
        $hasRefundablePayment = in_array($order->payment_status, ['paid', 'partially_refunded'], true);

        if ($to === OrderStatus::Cancelled) {
            // Restore coupon usage quota upon order cancellation.
            $this->reverseCouponUsage($order);
            if ($hasRefundablePayment) {
                $order->update(['requires_refund' => true]);
            }
        }

        // Flag paid returned order for refund processing.
        if ($to === OrderStatus::Returned && $hasRefundablePayment && $returnEffects) {
            $order->update(['requires_refund' => true]);
        }

        if ($order->payment_method === 'wallet' && $hasRefundablePayment
            && ($to === OrderStatus::Cancelled || ($to === OrderStatus::Returned && $returnEffects))) {
            $this->refundWalletOnOrderReversal($order, $actorId);
        }
    }

    private function refundWalletOnOrderReversal(Order $order, ?int $actorId): void
    {
        $payment = CustomerWalletTransaction::query()
            ->whereIn('reference_type', WalletService::ORDER_REFERENCES)
            ->where('reference_id', $order->id)
            ->where('type', 'checkout_payment')
            ->first();

        if ($payment === null) {
            return;
        }

        Customer::query()->whereKey($payment->customer_id)->lockForUpdate()->first();

        // Historical order credits predate the order_reversal type.
        $alreadyRefunded = BigDecimal::of((string) CustomerWalletTransaction::query()
            ->where('customer_id', $payment->customer_id)
            ->where(fn ($credits) => $credits
                ->where(fn ($direct) => $direct
                    ->whereIn('reference_type', WalletService::ORDER_REFERENCES)
                    ->where('reference_id', $order->id)
                    ->whereIn('type', ['refund', 'checkout_refund', 'order_reversal']))
                ->orWhere(fn ($returns) => $returns
                    ->whereIn('reference_type', WalletService::RETURN_REFERENCES)
                    ->whereIn('reference_id', ReturnRequest::query()->where('order_id', $order->id)->select('id'))
                    ->where('type', 'return_refund')))
            ->where('amount', '>', 0)
            ->sum('amount'));

        $remaining = BigDecimal::of((string) $payment->amount)->negated()->minus($alreadyRefunded);
        if ($remaining->isPositive()) {
            app(WalletService::class)->record(
                $payment->customer_id,
                (string) $remaining,
                'order_reversal',
                'order',
                $order->id,
                $actorId,
            );
        }

        $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
    }

    private function applyStatusColumnUpdates(Order $order, OrderStatus $to): void
    {
        $updates = ['status' => $to];
        if ($to === OrderStatus::Returned) {
            $updates['returned_at'] = $order->returned_at ?? now();
        }
        if ($to === OrderStatus::Confirmed && $order->payment_method === 'credit' && $order->due_at === null && $order->customer_id !== null) {
            $updates['due_at'] = $this->resolveCreditDueDate($order);
        }

        $order->update($updates);
    }

    private function resolveCreditDueDate(Order $order): Carbon
    {
        $terms = Customer::query()->whereKey($order->customer_id)->value('payment_terms_days');
        $days = $terms === null
            ? (int) $this->settings->get('wholesale_default_payment_terms_days', 30)
            : (int) $terms;

        return now()->addDays(max(0, $days));
    }

    private function settleCodPaymentOnDelivery(Order $order, OrderStatus $to): void
    {
        if ($to === OrderStatus::Delivered
            && $order->payment_method === 'cod'
            && $order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'paid']);
        }
    }

    private function recordStatusHistory(Order $order, OrderStatus $from, OrderStatus $to, ?Model $actor, ?string $notes, ?string $extraNote): void
    {
        // Truncate notes to fit the varchar(500) database column limit.
        $historyNotes = trim(($notes ?? '').($extraNote !== null ? ' — '.$extraNote : '')) ?: $notes;

        $order->statusHistories()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by_type' => $actor?->getMorphClass(),
            'changed_by_id' => $actor?->getKey(),
            'notes' => $historyNotes !== null ? mb_substr($historyNotes, 0, 500) : null,
        ]);
    }

    private function ensureConfirmedOrderAllocated(Order $order, OrderStatus $to, ?int $actorId): void
    {
        // Trigger item allocation if the order transitioned to confirmed without full allocation.
        if ($to === OrderStatus::Confirmed && ! $this->allocationService->isFullyAllocated($order)) {
            if ($this->allocationService->isAllocated($order)) {
                $this->allocationService->deallocate($order, $actorId);
            }
            $this->allocationService->allocate($order, performedBy: $actorId);
        }
    }

    /**
     * Checks if customer has approved wholesale/distributor status exempt from retail limits.
     */
    private function isWholesaleApproved(?Customer $customer): bool
    {
        return $customer !== null && $customer->isWholesaleApproved();
    }

    /**
     * Releases reservations and records sale stock movements for all items in the order.
     */
    private function shipOrder(Order $order, ?int $actorId): void
    {
        if (! $order->shipments()->exists()) {
            throw new BusinessException('لا يمكن تعليم الطلب كمشحون قبل إصدار بوليصة.');
        }

        $net = $this->netReservations($order);
        if ($net->isEmpty()) {
            throw new BusinessException('لا توجد حجوزات مخزون لهذا الطلب؛ لا يمكن الشحن قبل التأكيد والتوزيع.');
        }

        $levels = $this->lockLevelsForMovements($net);
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

        $this->incrementProductSalesCounts($order);
    }

    private function incrementProductSalesCounts(Order $order): void
    {
        $order->loadMissing('items');
        $productByVariant = ProductVariant::withTrashed()
            ->whereIn('id', $order->items->pluck('product_variant_id')->unique()->all())
            ->pluck('product_id', 'id');

        $salesByProduct = [];
        foreach ($order->items as $item) {
            $productId = $productByVariant->get($item->product_variant_id);
            $qty = (int) round((float) $item->qty);
            if ($productId !== null && $qty > 0) {
                $salesByProduct[(int) $productId] = ($salesByProduct[(int) $productId] ?? 0) + $qty;
            }
        }

        ksort($salesByProduct);
        foreach ($salesByProduct as $productId => $qty) {
            Product::query()->whereKey($productId)->increment('sales_count', $qty);
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

        $levels = $this->lockLevelsForMovements($net);
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
        $net = $this->unreturnedSaleMovements($order);
        if ($net->isEmpty()) {
            return false;
        }

        $levels = $this->lockLevelsForMovements($net);
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

        $this->applyReturnedQuantitiesToItems($order, $net);

        return true;
    }

    /**
     * @return Collection<int, array{product_variant_id: int, branch_id: int, location_id: int|null, net_qty: float, sale_unit_cost: mixed}>
     */
    private function unreturnedSaleMovements(Order $order): Collection
    {
        return $this->orderStockMovementsQuery($order)
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
            ->values()
            ->map(function (array $row): array {
                $receiving = $this->inventoryService->receivingLocation($row['branch_id']);
                if ($receiving !== null) {
                    $row['location_id'] = $receiving;
                }

                return $row;
            });
    }

    /**
     * @param  Collection<int, array{product_variant_id: int, branch_id: int, location_id: int|null, net_qty: float, sale_unit_cost: mixed}>  $net
     */
    private function applyReturnedQuantitiesToItems(Order $order, Collection $net): void
    {
        $returnedByVariant = $net->groupBy('product_variant_id')
            ->map(fn (Collection $rows): float => -$rows->sum('net_qty'));

        foreach ($order->items()->with('variant')->orderBy('id')->lockForUpdate()->get() as $item) {
            $variantId = $item->product_variant_id;
            $quantity = min((float) $item->qty - (float) $item->returned_qty, $returnedByVariant->get($variantId, 0));
            if ($quantity <= 0) {
                continue;
            }
            $item->increment('returned_qty', $quantity);
            $returnedByVariant->put($variantId, $returnedByVariant->get($variantId) - $quantity);
            if ($item->variant?->product_id) {
                Product::query()->whereKey($item->variant->product_id)
                    ->update(['sales_count' => DB::raw('GREATEST(sales_count - '.(int) round($quantity).', 0)')]);
            }
        }
    }

    /**
     * Computes net active stock reservations per variant/location for an order.
     *
     * @return Collection<int, array{product_variant_id: int, branch_id: int, location_id: int|null, net_qty: float}>
     */
    private function netReservations(Order $order): Collection
    {
        return $this->orderStockMovementsQuery($order)
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

    private function orderStockMovementsQuery(Order $order): Builder
    {
        return StockMovement::query()
            ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
            ->where('reference_id', $order->id);
    }

    /**
     * @param  Collection<int, array{product_variant_id: int, branch_id: int, location_id: int|null}>  $movements
     */
    private function lockLevelsForMovements(Collection $movements): Collection
    {
        return $this->inventoryService->lockLevels($movements->map(fn (array $row): array => [
            'product_variant_id' => $row['product_variant_id'],
            'branch_id' => $row['branch_id'],
            'location_id' => $row['location_id'],
        ])->all());
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

    // Instance-level cache avoids cross-test/Octane bleed of static properties.
    private ?int $systemActorId = null;

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

        if ($this->systemActorId === null) {
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
