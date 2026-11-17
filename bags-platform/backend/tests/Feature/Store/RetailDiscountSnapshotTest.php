<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\Orders\SalesChannel;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\RetailPriceSnapshot;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailDiscountSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_sale_filter_uses_refreshed_snapshot_and_live_fallback_when_stale(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        $discounted = Product::factory()->published()->create([
            'category_id' => $category->id,
            'sales_channel' => SalesChannel::Retail,
        ]);
        $regular = Product::factory()->published()->create([
            'category_id' => $category->id,
            'sales_channel' => SalesChannel::Retail,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $discounted->id,
            'retail_price' => 100,
            'compare_at_price' => 120,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $regular->id,
            'retail_price' => 100,
            'compare_at_price' => null,
        ]);

        app(RetailPriceSnapshot::class)->refresh([$discounted->id, $regular->id]);

        $this->assertTrue($discounted->fresh()->catalog_has_retail_discount);
        $this->assertFalse($regular->fresh()->catalog_has_retail_discount);
        $catalog = app(StoreCatalogService::class);
        $this->assertSame(
            [$discounted->id],
            $catalog->applyFilters($catalog->publishedBaseQuery(), ['on_sale' => true])->pluck('products.id')->all(),
        );

        // A missing or expired snapshot must keep correct results until the
        // scheduled refresher reaches this product.
        DB::table('products')->where('id', $discounted->id)->update([
            'catalog_has_retail_discount' => false,
            'catalog_price_refreshed_at' => null,
        ]);
        $this->assertSame(
            [$discounted->id],
            $catalog->applyFilters($catalog->publishedBaseQuery(), ['on_sale' => true])->pluck('products.id')->all(),
        );
    }
}
