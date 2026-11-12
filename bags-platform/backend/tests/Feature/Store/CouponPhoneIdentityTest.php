<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Pricing\CouponService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** F-15: deleting and re-registering with the same phone must not reset the per-customer coupon limit. */
class CouponPhoneIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_reregistration_with_the_same_phone_keeps_prior_usage(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit_per_customer' => 1]);
        $customer = Customer::factory()->create(['phone' => '01012345678']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        app(CouponService::class)->recordUsage($coupon, $order, $customer, 10.0);

        // The customer deletes the account and signs up again with the same phone.
        $customer->delete();
        $renewed = Customer::factory()->create(['phone' => '01012345678']);
        $secondOrder = Order::factory()->create(['customer_id' => $renewed->id]);

        try {
            app(CouponService::class)->recordUsage($coupon, $secondOrder, $renewed, 10.0);
            $this->fail('A re-registered phone must keep its prior coupon usage.');
        } catch (BusinessException) {
            $this->assertTrue(true);
        }
    }

    public function test_first_use_still_succeeds(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit_per_customer' => 1]);
        $customer = Customer::factory()->create(['phone' => '01098765432']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $usage = app(CouponService::class)->recordUsage($coupon, $order, $customer, 10.0);

        $this->assertSame('01098765432', $usage->phone_normalized);
    }
}
