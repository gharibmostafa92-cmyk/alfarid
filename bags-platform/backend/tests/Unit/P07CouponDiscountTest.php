<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Coupon;
use App\Services\Pricing\CouponService;
use PHPUnit\Framework\TestCase;

/**
 * task.txt item 26: CouponService::discountFor boundaries plus the
 * 0 ≤ discount ≤ subtotal property over seeded random inputs.
 */
class P07CouponDiscountTest extends TestCase
{
    private function coupon(array $overrides = []): Coupon
    {
        return new Coupon(array_merge([
            'code' => 'T', 'type' => 'percentage', 'value' => 10,
            'min_order_amount' => null, 'max_discount_amount' => null,
            'usage_limit' => null, 'usage_limit_per_customer' => 1,
            'used_count' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true,
        ], $overrides));
    }

    public function test_percentage_with_fractional_values_rounds_to_two_decimals(): void
    {
        $svc = new CouponService();
        $this->assertSame(33.33, $svc->discountFor($this->coupon(['value' => 33.33]), 99.99));
    }

    public function test_fixed_discount_capped_at_subtotal(): void
    {
        $svc = new CouponService();
        $this->assertSame(150.0, $svc->discountFor($this->coupon(['type' => 'fixed', 'value' => 200]), 150.0));
    }

    public function test_percentage_honors_max_discount_amount(): void
    {
        $svc = new CouponService();
        $this->assertSame(30.0, $svc->discountFor($this->coupon(['value' => 50, 'max_discount_amount' => 30]), 100.0));
    }

    public function test_free_shipping_and_zero_subtotal_yield_zero(): void
    {
        $svc = new CouponService();
        $this->assertSame(0.0, $svc->discountFor($this->coupon(['type' => 'free_shipping']), 150.0));
        $this->assertSame(0.0, $svc->discountFor($this->coupon(['value' => 50]), 0.0));
    }

    public function test_subtotal_equal_to_minimum_is_accepted_by_discount_math(): void
    {
        // discountFor itself is pure math: eligibility (min_order_amount) is
        // enforced by assertRedeemable, where subtotal < min rejects and
        // equality passes.
        $svc = new CouponService();
        $this->assertSame(10.0, $svc->discountFor($this->coupon(['value' => 10, 'min_order_amount' => 100]), 100.0));
    }

    public function test_discount_always_within_zero_and_subtotal(): void
    {
        $svc = new CouponService();
        mt_srand(20261105);
        for ($i = 0; $i < 500; $i++) {
            $type = mt_rand(0, 2) === 0 ? 'fixed' : 'percentage';
            $value = $type === 'fixed' ? mt_rand(0, 30000) / 100 : mt_rand(0, 10000) / 100;
            $max = mt_rand(0, 1) === 0 ? null : mt_rand(0, 20000) / 100;
            $subtotal = mt_rand(0, 50000) / 100;
            $discount = $svc->discountFor($this->coupon(['type' => $type, 'value' => $value, 'max_discount_amount' => $max]), $subtotal);
            $this->assertGreaterThanOrEqual(0.0, $discount, "iteration $i");
            $this->assertLessThanOrEqual($subtotal, $discount, "iteration $i");
        }
    }
}
