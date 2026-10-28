<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Events\StockLowDetected;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CartService;
use App\Services\Catalog\ProductMediaService;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Services\StoreCatalogService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class TaskCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->published(), 'retail_price' => 100, 'wholesale_price' => 80,
        ]);
    }

    private function stock(ProductVariant $variant, Location $location, float $qty, float $threshold = 0): StockLevel
    {
        return StockLevel::forceCreate([
            'product_variant_id' => $variant->id, 'branch_id' => $location->branch_id,
            'location_id' => $location->id, 'on_hand' => $qty, 'reserved' => 0, 'damaged' => 0,
            'reorder_level' => $threshold,
        ]);
    }

    public function test_checkout_rechecks_stale_variants_against_catalog_visibility(): void
    {
        $variant = $this->variant();
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
        $carts = app(CartService::class);
        $lines = $carts->pricedLines($cart, null);
        DB::table('products')->where('id', $variant->product_id)->update(['sales_channel' => 'wholesale']);
        $this->expectException(BusinessException::class);
        $carts->assertLinesPurchasable($lines);
    }

    public function test_hidden_cart_line_remains_removable_without_a_price_or_stock(): void
    {
        $variant = $this->variant();
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
        $variant->product->update(['sales_channel' => 'wholesale']);
        $item = app(CartService::class)->present($cart, null)['items'][0];
        $this->assertFalse($item['priced']);
        $this->assertSame(0.0, $item['max_qty']);
    }

    public function test_deleted_ancestor_hides_products_from_both_catalog_and_cart(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $variant = $this->variant();
        $variant->product->update(['category_id' => $child->id]);
        DB::table('categories')->where('id', $root->id)->update(['deleted_at' => now()]);
        $this->getJson('/api/v1/store/products/'.$variant->product->slug)->assertNotFound();
        $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 1])->assertUnprocessable();
    }

    public function test_product_and_cart_quantity_limits_follow_the_customer_and_sellable_stock(): void
    {
        $variant = $this->variant();
        $location = Location::factory()->create();
        $this->stock($variant, $location, 20000);
        $this->getJson('/api/v1/store/products/'.$variant->product->slug)->assertOk()->assertJsonPath('data.variants.0.max_qty', 10);
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
        $customer = Customer::factory()->create(['status' => 'approved', 'type' => 'retail']);
        $this->assertSame(50.0, app(CartService::class)->present($cart, $customer)['items'][0]['max_qty']);
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $this->assertSame(10000.0, app(CartService::class)->present($cart, $customer)['items'][0]['max_qty']);
    }

    public function test_receiving_and_inactive_locations_are_excluded_from_sellable_stock(): void
    {
        $variant = $this->variant();
        foreach ([['type' => 'receiving'], ['is_active' => false], []] as $attributes) {
            $this->stock($variant, Location::factory()->create($attributes), 3);
        }
        $this->assertSame(3.0, app(StoreCatalogService::class)->availabilityMap([$variant->id])[$variant->id]);
    }

    public function test_wholesale_fallback_and_display_base_prices_match_php_and_sql(): void
    {
        $variant = $this->variant();
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $pricing = app(PricingService::class);
        $php = $pricing->resolve($variant, $customer);
        $sql = app(StoreCatalogService::class)->variantPrice($pricing->withStorePrice(ProductVariant::query(), $customer)->findOrFail($variant->id), $customer);
        $this->assertSame(80.0, $php['unit_price']);
        $this->assertSame($php['unit_price'], $sql['unit_price']);
        $this->assertSame($php['base_price'], $sql['base_price']);
    }

    public function test_stock_branch_threshold_still_applies_when_the_row_has_its_own_threshold(): void
    {
        $variant = $this->variant();
        $variant->update(['reorder_level' => 10]);
        $location = Location::factory()->create();
        $this->stock($variant, $location, 7, 1);
        $this->stock($variant, Location::factory()->create(['branch_id' => $location->branch_id]), 5);
        Event::fake([StockLowDetected::class]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $location->branch_id, 'location_id' => $location->id,
            'type' => 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => -3,
            'created_by' => User::factory()->create()->id,
        ]);
        Event::assertDispatched(StockLowDetected::class, fn ($event) => $event->available === 9.0 && $event->reorderLevel === 10.0);
    }

    public function test_low_stock_notification_is_discarded_on_rollback_and_delayed_until_commit(): void
    {
        Event::fake([StockLowDetected::class]);
        DB::beginTransaction();
        StockLowDetected::dispatch(1, 1, 2.0, 5.0);
        Event::assertNotDispatched(StockLowDetected::class);
        DB::rollBack();
        Event::assertNotDispatched(StockLowDetected::class);
        DB::transaction(function (): void {
            StockLowDetected::dispatch(1, 1, 2.0, 5.0);
            Event::assertNotDispatched(StockLowDetected::class);
        });
        Event::assertDispatchedTimes(StockLowDetected::class, 1);
    }

    public function test_batch_stock_lock_rejects_a_location_from_another_branch(): void
    {
        $variant = $this->variant();
        $location = Location::factory()->create();
        $other = Branch::factory()->create();
        $this->expectException(BusinessException::class);
        DB::transaction(fn () => app(InventoryService::class)->lockLevels([[
            'product_variant_id' => $variant->id, 'branch_id' => $other->id, 'location_id' => $location->id,
        ]]));
    }

    public function test_reorder_covers_one_complete_owner_collection(): void
    {
        $variant = $this->variant();
        $product = $variant->product;
        $one = $product->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaCollection('images');
        $two = $product->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaCollection('images');
        $color = $variant->addMedia(UploadedFile::fake()->image('color.jpg'))->toMediaCollection('images');
        $service = app(ProductMediaService::class);
        $service->reorder($product, [$two->id, $one->id]);
        $this->assertSame(1, $two->fresh()->order_column);
        $this->expectException(BusinessException::class);
        $service->reorder($product, [$one->id, $color->id]);
    }

    public function test_signed_in_checkout_and_cart_limits_use_account_identity(): void
    {
        $customer = Customer::factory()->create();
        Auth::guard('sanctum')->setUser($customer);
        $request = Request::create('/api/v1/store/checkout', 'POST', [], server: ['REMOTE_ADDR' => '192.0.2.1']);
        $checkout = (RateLimiter::limiter('checkout'))($request);
        $cart = (RateLimiter::limiter('cart'))($request);
        $this->assertSame(['checkout-customer:'.$customer->id, 'checkout-customer-day:'.$customer->id], array_column($checkout, 'key'));
        $this->assertSame(['cart-customer:'.$customer->id], array_column($cart, 'key'));
    }

    public function test_facets_and_search_are_in_the_per_ip_expensive_query_budget(): void
    {
        foreach ([['/api/v1/store/catalog/filters', []], ['/api/v1/store/products', ['search' => 'bag']]] as [$url, $input]) {
            $request = Request::create($url, 'GET', $input);
            $route = (new Route('GET', $url, fn () => null))->name($input === [] ? 'store.catalog.filters' : 'store.products.index');
            $request->setRouteResolver(fn () => $route);
            $limits = (RateLimiter::limiter('store-catalog'))($request);
            $this->assertCount(2, $limits);
            $this->assertSame(60, $limits[1]->maxAttempts);
            $this->assertStringStartsWith('store-price-query:', $limits[1]->key);
        }
    }

    public function test_facets_cache_normalizes_category_and_occasion_order(): void
    {
        config(['cache.default' => 'task_shared', 'cache.stores.task_shared' => ['driver' => 'array']]);
        Cache::flush();
        $category = Category::factory()->create();
        $catalog = app(StoreCatalogService::class);
        $first = $catalog->facets(['category' => $category->slug, 'occasions' => ['work', 'casual'], 'in_stock' => '1']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $second = $catalog->facets(['category' => (string) $category->id, 'occasions' => ['casual', 'work'], 'in_stock' => true]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertEquals($first, $second);
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query['query'], 'products')));
    }

    public function test_video_upload_stages_outside_the_replacement_transaction(): void
    {
        $product = Product::factory()->create();
        $baseline = DB::transactionLevel();
        Media::creating(function (Media $media) use ($baseline): void {
            if ($media->collection_name === 'video_staging') {
                $this->assertSame($baseline, DB::transactionLevel());
            }
        });
        $video = UploadedFile::fake()->createWithContent('video.mp4', hex2bin('00000018667479706d703432000000006d70343269736f6d'));
        $media = app(ProductMediaService::class)->upload($product, ['collection' => 'video'], [], $video)->first();
        $this->assertSame('video', $media->fresh()->collection_name);
        $this->assertSame(1, $product->fresh()->getMedia('video')->count());
        $this->assertDatabaseMissing('media', ['collection_name' => 'video_staging']);
    }

    public function test_orphan_cleanup_removes_only_expired_staging_media_and_respects_dry_run(): void
    {
        $product = Product::factory()->create();
        $media = [];
        foreach (['old', 'recent'] as $name) {
            $media[$name] = $product->addMedia(UploadedFile::fake()->createWithContent($name.'.mp4', hex2bin('00000018667479706d703432000000006d70343269736f6d')))->toMediaCollection('video_staging');
        }
        DB::table('media')->where('id', $media['old']->id)->update(['created_at' => now()->subHours(2)]);
        $this->artisan('media:prune-orphans', ['--dry-run' => true, '--staging-only' => true])->assertSuccessful();
        $this->assertDatabaseHas('media', ['id' => $media['old']->id]);
        $this->artisan('media:prune-orphans', ['--staging-only' => true])->assertSuccessful();
        $this->assertDatabaseMissing('media', ['id' => $media['old']->id]);
        $this->assertDatabaseHas('media', ['id' => $media['recent']->id]);
    }
}
