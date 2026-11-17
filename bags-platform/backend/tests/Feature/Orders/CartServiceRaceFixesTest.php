<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Services\Orders\CartService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartServiceRaceFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_create_or_first_looks_up_by_cart_token_only(): void
    {
        $customer = Customer::factory()->create();
        $owned = Cart::factory()->create(['customer_id' => $customer->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolved = app(CartService::class)->resolveCart(null, $owned->cart_token, true);

        $tokenOnlyLookup = collect(DB::getQueryLog())->contains(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with(ltrim($sql), 'select')
                && str_contains($sql, 'carts')
                && str_contains($sql, 'cart_token')
                && ! str_contains($sql, 'customer_id');
        });

        $this->assertTrue($tokenOnlyLookup, 'Guest cart recovery must look up the conflicting row by cart_token only.');
        $this->assertNull($resolved->customer_id);
        $this->assertNotSame($owned->id, $resolved->id);
        $this->assertNotNull($owned->fresh());
    }

    public function test_merge_does_not_copy_a_guest_coupon_the_customer_cannot_redeem(): void
    {
        $customer = Customer::factory()->create();
        $coupon = Coupon::factory()->create(['is_active' => false]);
        $guest = Cart::factory()->create(['coupon_id' => $coupon->id]);
        CartItem::factory()->create([
            'cart_id' => $guest->id,
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'qty' => 1,
        ]);

        $merged = app(CartService::class)->mergeGuestCart($customer, $guest->cart_token);

        $this->assertTrue($merged);
        $account = Cart::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($account);
        $this->assertNull($account->coupon_id);
        $this->assertNull(Cart::query()->find($guest->id));
    }
}
