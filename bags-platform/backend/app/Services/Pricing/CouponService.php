<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles coupon validation, application, discount calculations, and atomic usage tracking.
 */
class CouponService
{
    // Uniform error prevents code enumeration and policy leaking.
    private const NOT_REDEEMABLE = 'كود الخصم غير صحيح أو غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.';

    /**
     * Validates a coupon code and associates it with the cart.
     *
     * @param  Cart  $cart  Target shopping cart
     * @param  string  $code  Raw coupon code
     * @param  float  $subtotal  Current cart subtotal
     * @param  Customer|null  $customer  Authenticated customer instance, if any
     * @return float Calculated discount amount
     *
     * @throws BusinessException If coupon is invalid or rate limited
     */
    public function validateAndApply(Cart $cart, string $code, float $subtotal, ?Customer $customer = null): float
    {
        $coupon = $this->findRedeemable($code, $subtotal, $customer);
        $this->enforceRateLimit($coupon, 'apply');

        $cart->update(['coupon_id' => $coupon->id]);

        return $this->discountFor($coupon, $subtotal);
    }

    /**
     * Enforces per-IP and per-coupon rate limits to mitigate brute-force and abuse.
     *
     * @param  Coupon  $coupon  Target coupon
     * @param  string  $action  Action key (e.g., 'apply')
     *
     * @throws BusinessException If rate limit is exceeded
     */
    public function enforceRateLimit(Coupon $coupon, string $action = 'apply'): void
    {
        $max = (int) config('store.coupon_apply_per_minute', 20);
        if ($max <= 0) {
            return;
        }

        $key = 'coupon:'.$action.':'.$coupon->id.':'.(request()->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new BusinessException(self::NOT_REDEEMABLE, [], 429);
        }
        RateLimiter::hit($key, 60);
    }

    /**
     * Re-validates a coupon ID during order checkout.
     *
     * @param  int  $couponId  Coupon identifier
     * @param  float  $subtotal  Current cart subtotal
     * @param  Customer|null  $customer  Authenticated customer instance, if any
     * @param  string|null  $guestPhone  Unauthenticated guest phone number
     * @return Coupon Validated coupon model
     *
     * @throws BusinessException If coupon is not eligible
     */
    public function redeemableById(int $couponId, float $subtotal, ?Customer $customer = null, ?string $guestPhone = null): Coupon
    {
        $coupon = Coupon::query()->find($couponId);
        if ($coupon === null) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
        $this->assertRedeemable($coupon, $subtotal, $customer, $guestPhone);

        return $coupon;
    }

    /**
     * Calculates the applicable merchandise discount.
     *
     * @param  Coupon  $coupon  Target coupon
     * @param  float  $subtotal  Order merchandise subtotal
     * @return float Monetary discount capped by maximum limits and subtotal
     */
    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        // Free shipping discounts apply to shipping fees during checkout, not merchandise subtotal.
        if ($subtotal <= 0 || $coupon->type === 'free_shipping') {
            return 0.0;
        }

        $discount = $coupon->type === 'percentage'
            ? $subtotal * (float) $coupon->value / 100
            : (float) $coupon->value;

        if ($coupon->max_discount_amount !== null) {
            $discount = min($discount, (float) $coupon->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    /**
     * Atomically records coupon usage for an order and increments total usage count.
     *
     * @param  Coupon  $coupon  Target coupon
     * @param  Order  $order  Associated completed order
     * @param  Customer|null  $customer  Authenticated customer, if any
     * @param  float  $discountAmount  Applied discount amount
     * @return CouponUsage Newly created usage record
     *
     * @throws BusinessException If usage limit was exceeded concurrently
     */
    public function recordUsage(Coupon $coupon, Order $order, ?Customer $customer, float $discountAmount): CouponUsage
    {
        $guestPhoneNormalized = $customer === null && $order->guest_phone !== null
            ? Customer::normalizePhone($order->guest_phone)
            : null;

        $phoneKey = $guestPhoneNormalized ?? $this->resolveCustomerPhone($customer);
        $identity = $phoneKey !== null
            ? 'phone:'.$phoneKey
            : ($customer !== null ? 'customer:'.$customer->id : 'anonymous');
        // Advisory transaction lock serializes concurrent checkouts per identity without locking table rows.
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['coupon-usage:'.$coupon->id.':'.$identity]);

        // Re-evaluate identity limit under the lock to prevent race conditions.
        $this->assertPerIdentityAllowed($coupon, $customer, $guestPhoneNormalized);

        // Conditional atomic increment ensures usage_limit is never exceeded without locking coupon row.
        $incremented = Coupon::query()
            ->whereKey($coupon->id)
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->increment('used_count');
        if ($incremented === 0) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }

        return CouponUsage::create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer?->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
            'guest_phone_normalized' => $guestPhoneNormalized,
        ]);
    }

    /**
     * Resolves an eligible coupon model by code.
     *
     * @param  string  $code  Raw coupon code
     * @param  float  $subtotal  Current subtotal
     * @param  Customer|null  $customer  Customer or null for guest
     * @return Coupon Validated coupon
     *
     * @throws BusinessException If coupon code is invalid or expired
     */
    private function findRedeemable(string $code, float $subtotal, ?Customer $customer): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();
        if ($coupon === null) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
        $this->assertRedeemable($coupon, $subtotal, $customer);

        return $coupon;
    }

    /**
     * Asserts that a coupon meets temporal validity, minimum subtotal, and per-identity limits.
     *
     * @param  Coupon  $coupon  Target coupon
     * @param  float  $subtotal  Current subtotal
     * @param  Customer|null  $customer  Customer or null for guest
     * @param  string|null  $guestPhone  Guest phone number
     *
     * @throws BusinessException If coupon rules are violated
     */
    private function assertRedeemable(Coupon $coupon, float $subtotal, ?Customer $customer, ?string $guestPhone = null): void
    {
        if (! $coupon->isRedeemableNow()) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }

        $guestPhoneNormalized = ($guestPhone !== null && trim($guestPhone) !== '')
            ? Customer::normalizePhone($guestPhone)
            : null;

        $this->assertPerIdentityAllowed($coupon, $customer, $guestPhoneNormalized);
    }

    /**
     * Verifies that the customer or guest phone has not reached the coupon usage ceiling.
     * Checks both customer IDs and normalized phone numbers across registered and guest checkouts.
     *
     * @param  Coupon  $coupon  Target coupon
     * @param  Customer|null  $customer  Customer or null for guest
     * @param  string|null  $guestPhoneNormalized  Pre-normalized E.164 phone
     *
     * @throws BusinessException If identity usage limit is reached
     */
    private function assertPerIdentityAllowed(Coupon $coupon, ?Customer $customer, ?string $guestPhoneNormalized): void
    {
        $limit = (int) $coupon->usage_limit_per_customer;
        if ($limit <= 0) {
            return;
        }

        $phones = array_values(array_unique(array_filter([
            $guestPhoneNormalized,
            $this->resolveCustomerPhone($customer),
        ])));
        $customerIds = $customer !== null ? [$customer->id] : [];
        if ($phones !== []) {
            $customerIds = array_values(array_unique([
                ...$customerIds,
                ...Customer::query()->whereIn('phone', $phones)->pluck('id')->all(),
            ]));
        }
        if ($customerIds === [] && $phones === []) {
            return;
        }

        // Exclude cancelled orders so customers regain their allowance when an order is cancelled.
        // A registered checkout and a later guest checkout with the same phone share one allowance.
        $usageCount = $coupon->usages()
            ->where(function ($query) use ($customerIds, $phones): void {
                if ($customerIds !== []) {
                    $query->whereIn('customer_id', $customerIds);
                }
                if ($phones !== []) {
                    if ($customerIds === []) {
                        $query->whereIn('guest_phone_normalized', $phones);
                    } else {
                        $query->orWhereIn('guest_phone_normalized', $phones);
                    }
                }
            })
            ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
            ->count();

        if ($usageCount >= $limit) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
    }

    /**
     * Extracts and normalizes customer phone into canonical E.164 format.
     *
     * @param  Customer|null  $customer  Target customer
     * @return string|null Normalized phone string or null
     */
    private function resolveCustomerPhone(?Customer $customer): ?string
    {
        if ($customer?->phone === null || trim((string) $customer->phone) === '') {
            return null;
        }

        return Customer::normalizePhone((string) $customer->phone);
    }
}
