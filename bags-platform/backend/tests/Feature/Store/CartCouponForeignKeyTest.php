<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Models\Cart;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CartCouponForeignKeyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deleting_a_coupon_clears_the_cart_reference(): void
    {
        $coupon = Coupon::factory()->create();
        $cart = Cart::factory()->create(['coupon_id' => $coupon->id]);

        $coupon->delete();

        $this->assertNull($cart->fresh()->coupon_id);
    }
}
