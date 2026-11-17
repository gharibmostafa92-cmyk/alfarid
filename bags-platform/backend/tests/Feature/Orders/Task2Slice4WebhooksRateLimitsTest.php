<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockBucket;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Orders\OrderAllocationService;
use App\Services\Orders\OrderService;
use App\Services\Shared\IdempotencyFingerprint;
use App\Services\Shared\PaymobRefundInquiry;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Task2Slice4WebhooksRateLimitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_high04_paymob_refund_inquiry_uses_short_backoff_when_auth_fails(): void
    {
        Cache::flush();
        Http::fake([
            'accept.paymob.com/api/auth/tokens' => Http::response(['detail' => 'down'], 500),
        ]);

        $inquiry = app(PaymobRefundInquiry::class);
        $this->assertNull($inquiry->amount('12345', '999', ['api_key' => 'secret', 'currency' => 'EGP']));
        $this->assertNull($inquiry->amount('12345', '999', ['api_key' => 'secret', 'currency' => 'EGP']));

        Http::assertSentCount(1);
    }

    public function test_high05_track_rate_limits_per_order_number_across_ips(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'ORD-TRACK-99',
            'guest_phone' => '01000000001',
            'guest_phone_normalized' => '01000000001',
        ]);

        for ($i = 1; $i <= 40; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->getJson("/api/v1/store/orders/{$order->order_number}/track?phone=01099999999")
                ->assertStatus(404);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.41'])
            ->getJson("/api/v1/store/orders/{$order->order_number}/track?phone=01099999999")
            ->assertStatus(429);
    }

    public function test_medium08_idempotency_fingerprint_handles_large_exponent_strings_and_qty_equivalence(): void
    {
        $this->assertSame(
            IdempotencyFingerprint::hash(['qty' => '5.00']),
            IdempotencyFingerprint::hash(['qty' => 5])
        );

        $this->expectException(BusinessException::class);
        IdempotencyFingerprint::hash(['qty' => '1e500']);
    }

    public function test_low03_ship_order_aggregates_sales_count_updates_per_product(): void
    {
        $staff = User::factory()->create();
        $branch = Branch::factory()->create();
        Location::factory()->create(['branch_id' => $branch->id]);

        $product = Product::factory()->create(['status' => 'published', 'sales_count' => 0]);
        $variantA = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'A',
            'sku' => 'SKU-A-'.uniqid(),
            'retail_price' => 100,
            'is_active' => true,
        ]);
        $variantB = $product->variants()->create([
            'name_ar' => 'B',
            'sku' => 'SKU-B-'.uniqid(),
            'retail_price' => 100,
            'is_active' => true,
        ]);

        foreach ([$variantA, $variantB] as $v) {
            StockLevel::query()->forceCreate([
                'branch_id' => $branch->id,
                'location_id' => null,
                'product_variant_id' => $v->id,
                'on_hand' => 10,
                'reserved' => 0,
            ]);
        }

        $order = Order::factory()->create([
            'status' => OrderStatus::Confirmed,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variantA->id,
            'product_name_snapshot' => 'Bag A',
            'sku_snapshot' => $variantA->sku,
            'qty' => 2,
            'unit_price' => 100,
            'line_total' => 200,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variantB->id,
            'product_name_snapshot' => 'Bag B',
            'sku_snapshot' => $variantB->sku,
            'qty' => 3,
            'unit_price' => 100,
            'line_total' => 300,
        ]);

        app(OrderAllocationService::class)->allocate($order, $branch->id, $staff->id);
        $company = ShippingCompany::factory()->create();
        Shipment::query()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-LOW03-1',
            'status' => 'created',
        ]);

        DB::enableQueryLog();
        app(OrderService::class)->changeStatus($order->fresh(), OrderStatus::Shipped, $staff);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, (int) $product->fresh()->sales_count);
        $productUpdates = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'update "products"') && str_contains($q['query'], 'sales_count'));
        $this->assertCount(1, $productUpdates);
    }
}
