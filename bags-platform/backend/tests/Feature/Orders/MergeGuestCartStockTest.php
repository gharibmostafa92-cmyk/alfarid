<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\CartService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MergeGuestCartStockTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_merge_caps_incoming_quantity_at_fulfillable_stock(): void
    {
        [$customer, $variant] = $this->purchasableVariantWithStock(5);
        $account = Cart::factory()->create(['customer_id' => $customer->id]);
        CartItem::factory()->create([
            'cart_id' => $account->id,
            'product_variant_id' => $variant->id,
            'qty' => 4,
        ]);
        $guest = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $guest->id,
            'product_variant_id' => $variant->id,
            'qty' => 4,
        ]);

        $merged = app(CartService::class)->mergeGuestCart($customer, $guest->cart_token);

        $this->assertTrue($merged);
        $this->assertSame(5.0, (float) CartItem::query()
            ->where('cart_id', $account->id)
            ->where('product_variant_id', $variant->id)
            ->value('qty'));
        $this->assertNull(Cart::query()->find($guest->id));
    }

    public function test_merge_keeps_account_quantity_when_fulfillable_stock_is_zero(): void
    {
        [$customer, $variant] = $this->purchasableVariantWithStock(0);
        $account = Cart::factory()->create(['customer_id' => $customer->id]);
        CartItem::factory()->create([
            'cart_id' => $account->id,
            'product_variant_id' => $variant->id,
            'qty' => 4,
        ]);
        $guest = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $guest->id,
            'product_variant_id' => $variant->id,
            'qty' => 2,
        ]);

        $merged = app(CartService::class)->mergeGuestCart($customer, $guest->cart_token);

        $this->assertTrue($merged);
        $this->assertSame(4.0, (float) CartItem::query()
            ->where('cart_id', $account->id)
            ->where('product_variant_id', $variant->id)
            ->value('qty'));
        $this->assertNull(Cart::query()->find($guest->id));
    }

    /**
     * @return array{0: Customer, 1: ProductVariant}
     */
    private function purchasableVariantWithStock(float $onHand): array
    {
        $customer = Customer::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->published(),
            'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['is_active' => true]);
        DB::table('stock_levels')->insert([
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => $onHand,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'reorder_level' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customer, $variant];
    }
}
