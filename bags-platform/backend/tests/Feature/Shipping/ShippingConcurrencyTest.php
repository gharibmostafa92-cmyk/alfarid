<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ShipmentStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Inventory\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

/**
 * سباقات حقيقية على PostgreSQL: قفل التسليم، مفتاح الطلب، وتفرّد السعر.
 */
class ShippingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_the_same_delivery_event_from_two_connections_settles_cod_once(): void
    {
        $this->seed(SettingSeeder::class);
        $company = ShippingCompany::factory()->create([
            'code' => 'bosta',
            'api_credentials' => ['webhook_secret' => 'S'],
        ]);
        $order = Order::factory()->create([
            'status' => OrderStatus::Shipped,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
        ]);
        $shipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-RACE',
            'status' => ShipmentStatus::OutForDelivery,
        ]);
        $body = json_encode([
            'event_id' => 'E1', 'awb_number' => 'AWB-RACE', 'status' => 'delivered',
        ], JSON_THROW_ON_ERROR);
        $job = $this->rawJob('POST', '/api/v1/webhooks/shipping/bosta', $body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'S'),
        ]);

        $results = $this->concurrentJobs([$job, $job]);
        $this->assertEqualsCanonicalizing(
            [['received' => true], ['received' => true, 'duplicate' => true]],
            array_column($results, 'body'),
        );
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertSame(
            ['duplicate', 'processed'],
            WebhookLog::query()->orderBy('status')->pluck('status')->all(),
        );
    }

    public function test_concurrent_progress_and_delivery_end_as_delivered(): void
    {
        $this->seed(SettingSeeder::class);
        $company = ShippingCompany::factory()->create([
            'code' => 'bosta',
            'api_credentials' => ['webhook_secret' => 'S'],
        ]);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-ORDER',
            'status' => ShipmentStatus::Created,
        ]);
        $jobs = [];
        foreach (['in_transit', 'delivered'] as $status) {
            $body = json_encode([
                'event_id' => 'race-'.$status, 'awb_number' => 'AWB-ORDER', 'status' => $status,
            ], JSON_THROW_ON_ERROR);
            $jobs[] = $this->rawJob('POST', '/api/v1/webhooks/shipping/bosta', $body, [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'S'),
            ]);
        }

        $results = $this->concurrentJobs($jobs);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
    }

    public function test_parallel_checkout_with_the_same_idempotency_key_creates_one_order(): void
    {
        $this->seed(SettingSeeder::class);
        $branch = Branch::factory()->create();
        $staff = User::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id,
            'retail_price' => 100,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $staff->id,
        ]);
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $cityId = City::factory()->create(['shipping_cost' => 0])->id;
        $body = json_encode([
            'recipient_name' => 'عميل',
            'recipient_phone' => '01012345678',
            'city_id' => $cityId,
            'shipping_address_line' => 'شارع 1',
            'payment_method' => 'cod',
            'expected_total' => 100,
        ], JSON_THROW_ON_ERROR);
        $job = $this->rawJob('POST', '/api/v1/store/checkout', $body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CART_SESSION' => $cart->cart_token,
            'HTTP_IDEMPOTENCY_KEY' => 'same-checkout-key',
        ]);

        $results = $this->concurrentJobs([$job, $job]);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $numbers = array_map(fn (array $result) => $result['body']['data']['order_number'], $results);
        $this->assertCount(1, array_unique($numbers));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_parallel_rates_for_the_same_company_and_zone_return_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $admin->createToken('race')->plainTextToken;
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        $body = json_encode([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'cod_fee' => 0,
            'estimated_days_min' => 1,
            'estimated_days_max' => 3,
        ], JSON_THROW_ON_ERROR);
        $job = $this->rawJob('POST', '/api/v1/admin/shipping-rates', $body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $results = $this->concurrentJobs([$job, $job]);
        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertSame(1, ShippingRate::query()->count());
        $rejected = collect($results)->first(fn (array $result) => $result['status'] === 422);
        $this->assertArrayHasKey('shipping_zone_id', $rejected['body']['errors']);
    }

    /** @param  array<string, string>  $server */
    private function rawJob(string $method, string $uri, string $body, array $server): string
    {
        return '$ready(); $kernel = app(Illuminate\\Contracts\\Http\\Kernel::class);'
            .'$request = Illuminate\\Http\\Request::create('.var_export($uri, true).', '.var_export($method, true).', [], [], [], '
            .var_export($server, true).', '.var_export($body, true).');'
            .'$response = $kernel->handle($request); $decoded = json_decode($response->getContent(), true);'
            .'$kernel->terminate($request, $response);'
            .'return ["status" => $response->getStatusCode(), "body" => $decoded];';
    }
}
