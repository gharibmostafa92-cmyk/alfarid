<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\OrderItem;
use App\Services\Orders\ReturnService;
use App\Services\Pricing\CouponService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class TaskTxtRoadmapReturnsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_refund_uses_the_paid_line_instead_of_subtracting_the_pricing_discount_twice(): void
    {
        $item = new OrderItem([
            'qty' => 2,
            'unit_price' => 80,
            'line_total' => 160,
            'discount_amount' => 40,
            'coupon_discount_amount' => 0,
            'tax_amount' => 0,
        ]);
        $method = new ReflectionMethod(ReturnService::class, 'netLineRefundCents');
        $cents = $method->invoke(app(ReturnService::class), $item);

        $this->assertSame(16000, $cents);
    }

    public function test_a_per_customer_coupon_is_refused_for_a_guest_phone(): void
    {
        $coupon = Coupon::factory()->create([
            'usage_limit_per_customer' => 1,
            'type' => 'fixed',
            'value' => 5,
            'is_active' => true,
        ]);

        $this->expectException(BusinessException::class);
        app(CouponService::class)->redeemableById($coupon->id, 100, null, '01000000000');
    }

    public function test_percentage_discount_rounds_half_up_on_exact_decimals(): void
    {
        $coupon = Coupon::factory()->create([
            'type' => 'percentage',
            'value' => 15,
            'usage_limit_per_customer' => 0,
            'is_active' => true,
        ]);

        $this->assertSame(3.0, app(CouponService::class)->discountFor($coupon, 20));
    }
}
