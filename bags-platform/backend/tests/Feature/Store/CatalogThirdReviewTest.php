<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Orders\CartService;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\RetailPriceSnapshot;
use App\Services\Auth\CustomerAuthService;
use App\Services\Orders\OrderService;
use App\Services\Pricing\PricingService;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CatalogThirdReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(Product $product, float $price): ProductVariant
    {
        return ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => $price, 'wholesale_price' => $price * 0.8]);
    }

    private function stock(ProductVariant $variant): void
    {
        $location = Location::factory()->create();
        StockLevel::forceCreate(['product_variant_id' => $variant->id, 'branch_id' => $location->branch_id, 'location_id' => $location->id,
            'on_hand' => 20, 'reserved' => 0, 'damaged' => 0]);
    }

    public function test_default_sort_is_featured_and_indexed_sorts_do_not_use_the_price_budget(): void
    {
        $ordinary = Product::factory()->published()->create(['is_featured' => false, 'sales_count' => 100]);
        $featured = Product::factory()->published()->create(['is_featured' => true]);
        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonPath('data.0.id', $featured->id);
        foreach (['featured', 'best_selling'] as $sort) {
            $limits = (RateLimiter::limiter('store-catalog'))(Request::create('/api/v1/store/products', 'GET', ['sort' => $sort]));
            $this->assertCount(1, $limits);
        }
        $this->getJson('/api/v1/store/products?sort=best_selling')->assertOk()->assertJsonPath('data.0.id', $ordinary->id);
    }

    public function test_snapshot_price_filter_and_sort_match_the_stocked_display_variant(): void
    {
        $product = Product::factory()->published()->create();
        $this->variant($product, 700);
        $stocked = $this->variant($product, 1600);
        $this->stock($stocked);
        $other = Product::factory()->published()->create();
        $this->stock($this->variant($other, 1000));
        app(RetailPriceSnapshot::class)->refresh();
        $this->assertSame('1600.00', (string) $product->fresh()->catalog_min_retail_price);
        $this->getJson('/api/v1/store/products?price_max=800')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/store/products?sort=price_asc')->assertOk()->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.1.price', 1600)->assertJsonPath('data.1.default_variant_id', $stocked->id);
    }

    public function test_snapshots_follow_timed_prices_and_stale_snapshots_fall_back_to_live_prices(): void
    {
        $this->freezeTime();
        Queue::fake();
        $product = Product::factory()->published()->create();
        $variant = $this->variant($product, 1000);
        $list = PriceList::create(['name' => 'Timed', 'type' => 'retail', 'starts_at' => now()->addMinutes(3), 'ends_at' => now()->addMinutes(6)]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 500]);
        app(RetailPriceSnapshot::class)->refresh();
        $this->assertSame('1000.00', (string) $product->fresh()->catalog_min_retail_price);
        $this->travel(4)->minutes();
        $this->artisan('store:refresh-retail-prices')->assertSuccessful();
        $this->assertSame('500.00', (string) $product->fresh()->catalog_min_retail_price);
        $this->travel(11)->minutes();
        $this->getJson('/api/v1/store/products?price_max=800')->assertOk()->assertJsonCount(0, 'data');
        Queue::assertPushed(RefreshCatalogRetailPrices::class);
    }

    public function test_shared_structural_facets_do_not_share_customer_specific_price_ranges(): void
    {
        config(['cache.default' => 'third_review', 'cache.stores.third_review' => ['driver' => 'array']]);
        $variant = $this->variant(Product::factory()->published()->create(), 1600);
        $first = Customer::factory()->create(['status' => 'approved']);
        $second = Customer::factory()->create(['status' => 'approved']);
        $list = PriceList::create(['name' => 'Private', 'type' => 'customer_specific', 'customer_id' => $first->id]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 500]);
        Auth::guard('sanctum')->setUser($first);
        $firstResult = app(StoreCatalogService::class)->facets([]);
        Auth::guard('sanctum')->setUser($second);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $secondResult = app(StoreCatalogService::class)->facets([]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(1, collect($firstResult['price_ranges'])->firstWhere('id', 'under-800')['count']);
        $this->assertSame(0, collect($secondResult['price_ranges'])->firstWhere('id', 'under-800')['count']);
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query['query'], 'GROUP BY LOWER(color_name_en)') || str_contains($query['query'], 'occasion_scope')));
    }

    public function test_stale_facets_remain_available_while_the_refresh_lock_is_held(): void
    {
        config(['cache.default' => 'third_review', 'cache.stores.third_review' => ['driver' => 'array']]);
        $this->freezeTime();
        $catalog = app(StoreCatalogService::class);
        $first = $catalog->facets([]);
        $key = 'store:facets:v3:'.hash('sha256', json_encode(['in_stock' => false])).':structure';
        $lock = Cache::lock('illuminate:cache:flexible:lock:'.$key, 120);
        $this->assertTrue($lock->get());
        try {
            $this->travel(61)->seconds();
            $this->assertEquals($first, $catalog->facets([]));
        } finally {
            $lock->release();
        }
    }

    public function test_facets_keep_array_contract_after_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        $product = Product::factory()->published()->create(['occasions' => ['work']]);
        $variant = $this->variant($product, 700);
        $variant->update(['color_name_en' => 'Rose', 'color_name_ar' => 'وردي', 'color_hex' => '#e89aa8']);
        $url = '/api/v1/store/catalog/filters?category='.$product->category_id;
        for ($request = 0; $request < 2; $request++) {
            $response = $this->getJson($url)->assertOk()->assertJsonPath('data.colors.0.name_en', 'Rose')
                ->assertJsonPath('data.price_ranges.0.count', 1);
            $this->assertTrue(array_is_list($response->json('data.colors')));
            $this->assertTrue(array_is_list($response->json('data.occasions')));
            $this->assertTrue(array_is_list($response->json('data.price_ranges')));
        }
    }

    public function test_abandoned_image_retry_recovers_after_fifteen_minutes(): void
    {
        $product = Product::factory()->create();
        $id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $old = $product->addMedia(UploadedFile::fake()->image('abandoned.jpg'))->withCustomProperties(['client_upload_id' => $id])->toMediaCollection('images_staging');
        DB::table('media')->where('id', $old->id)->update(['created_at' => now()->subMinutes(16)]);
        $new = app(ProductMediaService::class)->upload($product, ['collection' => 'images', 'client_upload_ids' => [$id]], [UploadedFile::fake()->image('retry.jpg')])->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('images', $new->collection_name);
        $this->assertDatabaseMissing('media', ['id' => $old->id]);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_fractional_historical_cart_cannot_be_checked_out_or_added_through_the_service(): void
    {
        $variant = $this->variant(Product::factory()->published()->create(), 100);
        $cart = Cart::factory()->create();
        try {
            app(CartService::class)->addItem($cart, null, $variant->id, 0.5);
            $this->fail('Fractional cart addition accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 0.5]);
        $this->expectException(BusinessException::class);
        app(OrderService::class)->createFromCart($cart, ['recipient_phone' => '01012345678']);
    }

    public function test_occasions_database_constraint_rejects_objects_and_unknown_values(): void
    {
        $product = Product::factory()->create();
        foreach (['{"work":true}', '["unknown"]'] as $value) {
            try {
                DB::transaction(fn () => DB::table('products')->where('id', $product->id)->update(['occasions' => $value]));
                $this->fail('Invalid occasions accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
    }

    public function test_customer_tokens_have_explicit_expiry_even_if_global_expiry_is_disabled(): void
    {
        config(['sanctum.expiration' => null, 'auth.customer_token_minutes' => 120]);
        $customer = Customer::factory()->create(['status' => 'approved']);
        app(CustomerAuthService::class)->issueToken($customer, 'store-token');
        $this->assertEqualsWithDelta(now()->addMinutes(120)->timestamp, $customer->tokens()->firstOrFail()->expires_at->timestamp, 1);
    }

    public function test_sql_and_php_prices_agree_across_retail_wholesale_and_group_contexts(): void
    {
        $group = CustomerGroup::create(['name' => 'Group', 'code' => 'third-review', 'discount_percentage' => '12.50']);
        $buyers = [null, Customer::factory()->wholesale()->create(['status' => 'approved']),
            Customer::factory()->create(['status' => 'approved', 'customer_group_id' => $group->id])];
        $pricing = app(PricingService::class);
        foreach ([71.13, 125.57, 900.04, 1577.31] as $price) {
            $variant = $this->variant(Product::factory()->published()->create(), $price);
            $list = PriceList::create(['name' => 'Retail tier', 'type' => 'retail']);
            $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => round($price * 0.93, 2)]);
            foreach ($buyers as $buyer) {
                $expected = $pricing->resolve($variant, $buyer, 1);
                $loaded = $pricing->withStorePrice(ProductVariant::query(), $buyer)->findOrFail($variant->id);
                $actual = app(StoreCatalogService::class)->variantPrice($loaded, $buyer);
                $this->assertSame($expected['unit_price'], $actual['unit_price']);
                $this->assertSame($expected['base_price'], $actual['base_price']);
            }
        }
    }

    public function test_a_fresh_public_snapshot_does_not_execute_the_price_list_subplans(): void
    {
        $variant = $this->variant(Product::factory()->published()->create(), 1000);
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail']);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 900]);
        app(RetailPriceSnapshot::class)->refresh();
        $catalog = app(StoreCatalogService::class);
        $query = $catalog->applyFilters($catalog->publishedQuery()->withoutEagerLoads(), ['price_min' => 1, 'sort' => 'price_asc'])->limit(10);
        $explain = DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $plan = json_decode($explain->{'QUERY PLAN'}, true)[0]['Plan'];
        $scans = [];
        $visit = function (array $node) use (&$visit, &$scans): void {
            if (($node['Relation Name'] ?? null) === 'price_list_items') {
                $scans[] = $node['Actual Loops'];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $visit($child);
            }
        };
        $visit($plan);
        $this->assertNotEmpty($scans);
        $this->assertSame(0, array_sum($scans), 'Fresh snapshots must skip live retail price-list lookups.');
    }

    public function test_showcase_cache_contains_only_ids_and_reloads_private_prices(): void
    {
        config(['cache.default' => 'third_review', 'cache.stores.third_review' => ['driver' => 'array']]);
        $variant = $this->variant(Product::factory()->published()->create(['is_featured' => true]), 1600);
        $first = Customer::factory()->create(['status' => 'approved']);
        $second = Customer::factory()->create(['status' => 'approved']);
        $list = PriceList::create(['name' => 'Private showcase', 'type' => 'customer_specific', 'customer_id' => $first->id]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 500]);
        Auth::forgetGuards();
        $this->withToken($first->createToken('first')->plainTextToken)->getJson('/api/v1/store/showcase')->assertOk()->assertJsonPath('data.0.price', 500);
        Auth::forgetGuards();
        $this->withToken($second->createToken('second')->plainTextToken)->getJson('/api/v1/store/showcase')->assertOk()->assertJsonPath('data.0.price', 1600);
        $this->assertSame([$variant->product_id], Cache::get('store:showcase:ids:6'));
    }

    public function test_facets_cache_normalized_search_and_execute_at_most_three_queries_on_cold_miss(): void
    {
        config(['cache.default' => 'third_review', 'cache.stores.third_review' => ['driver' => 'array']]);
        $product = Product::factory()->published()->create(['name_en' => 'Midnight Tote Bag', 'occasions' => ['work']]);
        $variant = $this->variant($product, 950);
        $variant->update(['color_name_en' => 'Noir', 'color_name_ar' => 'أسود', 'color_hex' => '#111111']);
        $other = Product::factory()->published()->create(['name_en' => 'Summer Clutch', 'occasions' => ['evening']]);
        $this->variant($other, 700);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $first = app(StoreCatalogService::class)->facets(['search' => '  Tote  ']);
            $coldQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $facetQueries = array_filter($coldQueries, fn ($q) => str_contains($q['query'], 'product_variants')
            || str_contains($q['query'], 'occasion_scope')
            || str_contains($q['query'], 'price_scope')
            || str_contains($q['query'], 'products'));
        $this->assertLessThanOrEqual(3, count($facetQueries));
        $this->assertSame(1, $first['total']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $second = app(StoreCatalogService::class)->facets(['search' => 'tote']);
            $warmQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($first, $second);
        $this->assertCount(0, array_filter($warmQueries, fn ($q) => str_contains($q['query'], 'GROUP BY LOWER(color_name_en)') || str_contains($q['query'], 'occasion_scope') || str_contains($q['query'], 'price_scope')));
    }

    public function test_catalog_pagination_caps_page_at_100(): void
    {
        $this->getJson('/api/v1/store/products?page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->getJson('/api/v1/store/products?page=100')->assertOk();
    }

    public function test_category_brand_and_variant_mutations_invalidate_storefront_caches(): void
    {
        config(['cache.default' => 'third_review', 'cache.stores.third_review' => ['driver' => 'array']]);
        $category = \App\Models\Category::factory()->create(['is_active' => true, 'name_en' => 'Initial Cat']);
        $brand = \App\Models\Brand::factory()->create(['is_active' => true, 'name_en' => 'Initial Brand']);
        $product = Product::factory()->published()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $product->addMedia(UploadedFile::fake()->image('bag.jpg'))->toMediaCollection('images');
        $variant = $this->variant($product, 1200);

        $catKey = 'store:categories:v1:'.app()->getLocale();
        $this->getJson('/api/v1/store/categories')->assertOk()->assertJsonFragment(['name_en' => 'Initial Cat']);
        $this->getJson('/api/v1/store/catalog/filters')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertNotNull(Cache::get($catKey));
        $this->assertNotNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        $initialFacetsVersion = StoreCatalogService::facetsVersion();

        app(\App\Services\Catalog\CategoryService::class)->update($category, ['name_en' => 'Renamed Cat'], null);
        $this->assertNull(Cache::get($catKey));
        $this->assertNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        $this->assertGreaterThan($initialFacetsVersion, StoreCatalogService::facetsVersion());

        $v2 = StoreCatalogService::facetsVersion();
        $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertNotNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        app(\App\Services\Catalog\BrandService::class)->update($brand, ['name_en' => 'Renamed Brand'], null);
        $this->assertNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        $this->assertGreaterThan($v2, StoreCatalogService::facetsVersion());

        $v3 = StoreCatalogService::facetsVersion();
        $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertNotNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        app(\App\Services\Catalog\VariantService::class)->update($variant, ['compare_at_price' => 1500]);
        $this->assertNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        $this->assertGreaterThan($v3, StoreCatalogService::facetsVersion());
    }

    public function test_discounted_filter_and_home_exclude_group_scoped_retail_lists_and_keep_populated_offers(): void
    {
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'vip-retail', 'discount_percentage' => 0]);
        $groupOnlyProduct = Product::factory()->published()->create(['name_en' => 'Group Scoped Only']);
        $groupVariant = $this->variant($groupOnlyProduct, 1000);
        $groupList = PriceList::create(['name' => 'Group Only List', 'type' => 'customer_group', 'customer_group_id' => $group->id]);
        $groupList->items()->create(['product_variant_id' => $groupVariant->id, 'min_qty' => 1, 'price' => 800]);

        $this->getJson('/api/v1/store/products?on_sale=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $groupOnlyProduct->id]);

        for ($i = 0; $i < 12; $i++) {
            $p = Product::factory()->published()->create(['sales_count' => 100 + $i]);
            $inStockFull = $this->variant($p, 1000);
            $this->stock($inStockFull);
            ProductVariant::factory()->create([
                'product_id' => $p->id,
                'retail_price' => 700,
                'compare_at_price' => 1000,
                'sort_order' => 2,
            ]);
        }

        $genuineOffer = Product::factory()->published()->create(['sales_count' => 1]);
        $offerVariant = ProductVariant::factory()->create([
            'product_id' => $genuineOffer->id,
            'retail_price' => 600,
            'compare_at_price' => 900,
        ]);
        $this->stock($offerVariant);

        Cache::forget(StoreCatalogService::HOME_CACHE_KEY);
        $home = $this->getJson('/api/v1/store/home')->assertOk();
        $discountedIds = collect($home->json('data.discounted_products'))->pluck('id')->all();
        $this->assertContains($genuineOffer->id, $discountedIds);
    }
}
