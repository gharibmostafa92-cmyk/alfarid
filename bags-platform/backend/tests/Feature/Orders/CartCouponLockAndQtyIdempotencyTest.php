<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\Pricing\CouponService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CartCouponLockAndQtyIdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_apply_coupon_validates_inside_a_transaction_and_returns_the_written_discount(): void
    {
        $cart = Cart::factory()->create();
        $baseline = DB::transactionLevel();
        $coupons = Mockery::mock(CouponService::class);
        $coupons->shouldReceive('validateAndApply')->once()->andReturnUsing(function () use ($baseline) {
            $this->assertGreaterThan($baseline, DB::transactionLevel());

            return 5.0;
        });
        $this->app->instance(CouponService::class, $coupons);

        $this->withHeader('X-Cart-Session', $cart->cart_token)
            ->postJson('/api/v1/store/cart/coupon', ['code' => 'SAVE5'])
            ->assertOk()
            ->assertJsonPath('data.coupon_discount', 0)
            ->assertJsonPath('data.cart.totals.coupon_discount', 0);
    }

    public function test_setting_qty_zero_on_a_missing_line_returns_the_current_cart(): void
    {
        $cart = Cart::factory()->create();
        $variant = ProductVariant::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => 2,
        ]);

        $this->withHeader('X-Cart-Session', $cart->cart_token)
            ->putJson('/api/v1/store/cart/items/999999999', ['qty' => 0])
            ->assertOk()
            ->assertJsonPath('data.id', $cart->id)
            ->assertJsonPath('data.items.0.id', $item->id);

        $this->withHeader('X-Cart-Session', $cart->cart_token)
            ->putJson('/api/v1/store/cart/items/999999998', ['qty' => 1])
            ->assertNotFound();

        $this->assertSame(1, CartItem::query()->where('cart_id', $cart->id)->count());
    }
}
