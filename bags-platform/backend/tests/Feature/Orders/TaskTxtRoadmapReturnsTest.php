<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
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

    public function test_a_per_customer_coupon_allows_a_guest_phone_only_until_its_allowance_is_used(): void
    {
        $coupon = Coupon::factory()->create([
            'usage_limit_per_customer' => 1,
            'type' => 'fixed',
            'value' => 5,
            'is_active' => true,
        ]);
        $service = app(CouponService::class);
        $phone = Customer::normalizePhone('01000000000');

        // Finding 7: a guest who supplies a phone may preview/redeem the coupon.
        $this->assertSame($coupon->id, $service->redeemableById($coupon->id, 100, null, '01000000000')->id);

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'order_id' => Order::factory()->create([
                'guest_phone' => '01000000000',
                'customer_id' => null,
            ])->id,
            'discount_amount' => 5,
            'guest_phone_normalized' => $phone,
            'phone_normalized' => $phone,
        ]);

        $this->expectException(BusinessException::class);
        $service->redeemableById($coupon->id, 100, null, '+20 100 000 0000');
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
