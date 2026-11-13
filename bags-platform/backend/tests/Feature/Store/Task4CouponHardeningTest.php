<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\Pricing\CouponService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class Task4CouponHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_coupon_rate_limit_throws_429_with_clear_throttle_message_not_not_redeemable(): void
    {
        config(['store.coupon_apply_per_minute' => 2]);
        RateLimiter::clear('coupon:apply:ip:'.hash('sha256', (string) request()->ip()));
        RateLimiter::clear('coupon:apply:1:'.hash('sha256', 'ip:'.request()->ip()));

        $service = app(CouponService::class);
        $coupon = Coupon::factory()->create(['code' => 'RATE429', 'min_order_amount' => 10]);

        $cart = Cart::factory()->create();

        // 2 calls within limit
        $service->validateAndApply($cart, 'RATE429', 100.0);
        $service->validateAndApply($cart, 'RATE429', 100.0);

        // 3rd call exceeds limit
        try {
            $service->validateAndApply($cart, 'RATE429', 100.0);
            $this->fail('Expected rate limit exception');
        } catch (BusinessException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(CouponService::RATE_LIMIT_EXCEEDED, $e->getMessage());
            $this->assertStringContainsString('تجاوز', $e->getMessage());
        }
    }

    public function test_spoofed_cart_session_does_not_reset_ip_coupon_limit(): void
    {
        config([
            'auth.customer_limits.coupon_guest_ip_minute' => 1,
            'store.coupon_apply_per_minute' => 100,
        ]);
        $ip = (string) request()->ip();
        RateLimiter::clear('coupon:apply:ip:'.hash('sha256', $ip));

        $service = app(CouponService::class);
        Coupon::factory()->create(['code' => 'TOKENBYPASS', 'min_order_amount' => 10]);
        $cart = Cart::factory()->create();

        request()->headers->set('X-Cart-Session', 'attacker-token-a');
        $service->validateAndApply($cart, 'TOKENBYPASS', 100.0);

        // Rotating an unverified cart token must not grant a fresh IP budget.
        request()->headers->set('X-Cart-Session', 'attacker-token-b');

        try {
            $service->validateAndApply($cart, 'TOKENBYPASS', 100.0);
            $this->fail('Rotating X-Cart-Session must not bypass the IP coupon limit.');
        } catch (BusinessException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(CouponService::RATE_LIMIT_EXCEEDED, $e->getMessage());
        }
    }

    public function test_authenticated_customer_gets_a_stable_coupon_identity_bucket(): void
    {
        config([
            'auth.customer_limits.coupon_guest_ip_minute' => 100,
            'auth.customer_limits.coupon_customer_minute' => 1,
            'store.coupon_apply_per_minute' => 100,
        ]);
        $customer = Customer::factory()->create();
        $service = app(CouponService::class);
        Coupon::factory()->create(['code' => 'CUSTOMERBUCKET', 'min_order_amount' => 10]);
        $cart = Cart::factory()->create();

        $service->validateAndApply($cart, 'CUSTOMERBUCKET', 100.0, $customer);

        try {
            $service->validateAndApply($cart, 'CUSTOMERBUCKET', 100.0, $customer);
            $this->fail('The same customer must share one coupon identity bucket.');
        } catch (BusinessException $e) {
            $this->assertSame(429, $e->status);
        }
    }

    public function test_shipping_options_does_not_zero_cost_when_free_shipping_coupon_min_amount_unmet(): void
    {
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 30]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => ShippingCompany::factory()->create(['is_active' => true])->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'free_shipping_threshold' => 100000,
            'cod_fee' => 10,
        ]);

        $coupon = Coupon::factory()->create([
            'code' => 'FREESHIP1000',
            'type' => 'free_shipping',
            'min_order_amount' => 1000.0,
            'is_active' => true,
        ]);

        $cart = Cart::factory()->create([
            'coupon_id' => $coupon->id,
        ]);

        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 200]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        $response = $this->withHeader('X-Cart-Session', $cart->cart_token)
            ->postJson('/api/v1/store/checkout/shipping-options', [
                'city_id' => $city->id,
                'payment_method' => 'cod',
            ]);

        $response->assertOk();
        // Since cart is 200 (below min_order_amount of 1000), shipping cost should NOT be waived to 0
        $this->assertGreaterThan(0, (float) $response->json('data.preview.shipping'));
    }
}
