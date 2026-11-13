<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\StoreCatalogService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class Task4FacetsOnSaleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_facets_filter_by_on_sale_and_discounted_interchangeably(): void
    {
        $category = Category::factory()->create();

        // Product 1: Regular price (not on sale)
        $product1 = Product::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product1->id,
            'retail_price' => 200,
            'compare_at_price' => null,
            'is_active' => true,
        ]);

        // Product 2: Discounted (on sale)
        $product2 = Product::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product2->id,
            'retail_price' => 150,
            'compare_at_price' => 250,
            'is_active' => true,
        ]);

        $service = app(StoreCatalogService::class);

        // All facets without on_sale: total = 2
        $allFacets = $service->facets([]);
        $this->assertSame(2, $allFacets['total']);

        // With on_sale = 1: total = 1
        $onSaleFacets = $service->facets(['on_sale' => 1]);
        $this->assertSame(1, $onSaleFacets['total']);

        // With discounted = 1: total = 1
        $discountedFacets = $service->facets(['discounted' => 1]);
        $this->assertSame(1, $discountedFacets['total']);
    }
}
