<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * H1: the retail cart enforces the storefront published rule — wholesale-only
 * products and products under a disabled category cannot be purchased even
 * though they are active + published + non-deleted.
 */
class CartPurchasabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function stockedVariant(Product $product): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create(['is_active' => true]);
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        StockLevel::forceCreate([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 0,
        ]);

        return $variant;
    }

    public function test_wholesale_only_variant_is_rejected_from_retail_cart(): void
    {
        $product = Product::factory()->create([
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        $variant = $this->stockedVariant($product);

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_variant_under_disabled_category_is_rejected_from_cart(): void
    {
        $parent = Category::factory()->create(['is_active' => false]);
        $child = Category::factory()->create(['parent_id' => $parent->id, 'is_active' => true]);
        $product = Product::factory()->create([
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Both,
            'category_id' => $child->id,
        ]);
        $variant = $this->stockedVariant($product);

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_retail_variant_in_active_category_is_accepted(): void
    {
        $product = Product::factory()->create([
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Both,
        ]);
        $variant = $this->stockedVariant($product);

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
    }
}
