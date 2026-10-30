<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CartService;
use App\Services\Catalog\ProductMediaService;
use App\Services\OrderAllocationService;
use App\Services\StoreCatalogService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class FollowupAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(Product $product, float $price = 100): ProductVariant
    {
        return ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => $price]);
    }

    private function stock(ProductVariant $variant, Location $location, int $qty): void
    {
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $location->branch_id, 'location_id' => $location->id,
            'on_hand' => $qty, 'reserved' => 0, 'damaged' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rotating_ips_enforce_the_identifier_budget_after_checking_credentials(): void
    {
        config(['auth.unified_login_identifier_failures_hour' => 2]);
        foreach (['192.0.2.1', '192.0.2.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        Hash::shouldReceive('check')->once()->andReturn(false);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/api/v1/auth/login', ['login' => 'UNKNOWN@example.test', 'password' => 'another'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_checkout_middleware_has_no_daily_victim_phone_bucket(): void
    {
        $request = Request::create('/api/v1/store/checkout', 'POST', ['recipient_phone' => '01012345678']);
        $keys = array_column((RateLimiter::limiter('checkout'))($request), 'key');
        $this->assertContains('checkout-phone:'.hash('sha256', Customer::normalizePhone('01012345678')), $keys);
        $this->assertNotContains('checkout-phone-day:'.hash('sha256', Customer::normalizePhone('01012345678')), $keys);
    }

    public function test_listing_prefers_a_stocked_variant_without_exposing_exact_sales(): void
    {
        $product = Product::factory()->published()->create();
        $this->variant($product, 50);
        $stocked = $this->variant($product, 100);
        $this->stock($stocked, Location::factory()->create(), 5);
        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonPath('data.0.default_variant_id', $stocked->id)
            ->assertJsonPath('data.0.price', 100)->assertJsonMissingPath('data.0.sales_count');
    }

    public function test_damaged_locations_cannot_supply_an_order(): void
    {
        $variant = $this->variant(Product::factory()->published()->create());
        $location = Location::factory()->create(['type' => 'damaged']);
        $this->stock($variant, $location, 50);
        $this->assertSame(0.0, app(StoreCatalogService::class)->availabilityMap([$variant->id])[$variant->id] ?? 0.0);
        $order = Order::factory()->create();
        OrderItem::factory()->forVariant($variant)->create(['order_id' => $order->id, 'qty' => 1]);
        $this->expectException(BusinessException::class);
        app(OrderAllocationService::class)->allocate($order, performedBy: User::factory()->create()->id);
    }

    public function test_images_upload_outside_the_link_transaction_and_conversions_are_queued(): void
    {
        Queue::fake();
        $product = Product::factory()->create();
        $baseline = DB::transactionLevel();
        Media::creating(function (Media $media) use ($baseline): void {
            if ($media->collection_name === 'images_staging') {
                $this->assertSame($baseline, DB::transactionLevel());
            }
        });
        $service = app(ProductMediaService::class);
        $id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $first = $service->upload($product, ['collection' => 'images', 'client_upload_ids' => [$id]], [UploadedFile::fake()->image('first.jpg')])->first();
        $second = $service->upload($product, ['collection' => 'images', 'client_upload_ids' => [$id]], [UploadedFile::fake()->image('retry.jpg')])->first();
        $this->assertSame($first->id, $second->id);
        $this->assertSame('images', $first->collection_name);
        $this->assertFalse($first->hasGeneratedConversion('thumb'));
        Storage::disk('r2_public')->assertExists($first->getPathRelativeToRoot());
        Queue::assertPushed(PerformConversionsJob::class, 1);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_facets_price_ranges_are_computed_in_a_single_price_query(): void
    {
        foreach ([500, 1000, 2000] as $price) {
            $this->variant(Product::factory()->published()->create(['occasions' => ['work']]), $price);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $facets = app(StoreCatalogService::class)->facets([]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(3, $facets['total']);
        $this->assertSame([1, 1, 1], collect($facets['price_ranges'])->pluck('count')->all());
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'join lateral')));
        $this->assertSame(3, collect($facets['occasions'])->firstWhere('id', 'work')['count']);
    }

    public function test_customer_list_surcharge_does_not_create_a_negative_discount_or_subtotal(): void
    {
        $variant = $this->variant(Product::factory()->published()->create(), 100);
        $customer = Customer::factory()->create(['status' => 'approved']);
        $list = PriceList::create(['name' => 'Specific', 'type' => 'customer_specific', 'customer_id' => $customer->id]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 150]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 2]);
        $result = app(CartService::class)->present($cart, $customer);
        $this->assertSame(0.0, $result['items'][0]['line_discount']);
        $this->assertSame(300.0, $result['totals']['subtotal']);
        $this->assertSame(0.0, $result['totals']['pricing_discount']);
    }

    public function test_transient_database_conflicts_are_retryable_only_for_safe_requests(): void
    {
        foreach (['55P03', '40P01', '40001'] as $state) {
            $previous = new \PDOException('Transient database conflict');
            $previous->errorInfo = [$state, null, 'Transient database conflict'];
            $exception = new QueryException('pgsql', 'select 1', [], $previous);
            foreach (['GET' => 503, 'POST' => 409] as $method => $status) {
                $request = Request::create('/api/v1/store/products', $method);
                $request->headers->set('Accept', 'application/json');
                $response = app(ExceptionHandler::class)->render($request, $exception);
                $this->assertSame($status, $response->getStatusCode());
                $this->assertSame('1', $response->headers->get('Retry-After'));
            }
        }
    }
}
