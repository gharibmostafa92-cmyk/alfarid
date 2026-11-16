<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Enums\Catalog\PriceListType;
use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Pricing\PriceListService;
use App\Services\Pricing\PricingService;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\WholesaleQuickOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task6Slice3RegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Customer $trader;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('inventory.all_branches');

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->trader = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => 100000,
            'payment_terms_days' => 30,
        ]);
        $city = City::factory()->create();
        CustomerAddress::query()->create([
            'customer_id' => $this->trader->id,
            'city_id' => $city->id,
            'address_line' => '10 شارع التحرير',
            'is_default' => true,
        ]);
    }

    private function createVariant(string $sku = 'SPH-101', float $wholesalePrice = 100, ?string $barcode = null): ProductVariant
    {
        $product = Product::factory()->create([
            'status' => 'published',
            'sales_channel' => 'both',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'barcode' => $barcode,
            'is_active' => true,
            'retail_price' => $wholesalePrice + 50,
            'wholesale_price' => $wholesalePrice,
        ]);
        StockLevel::forceCreate([
            'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 100,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
        ]);

        return $variant;
    }

    public function test_f07_quotation_rejects_zero_unit_price_and_inactive_variant_and_populates_recipient(): void
    {
        $v1 = $this->createVariant('Q-V1', 100);
        $v2 = $this->createVariant('Q-V2', 0);

        $service = app(QuotationService::class);
        $quotation = $service->create($this->trader, [
            'items' => [
                ['product_variant_id' => $v1->id, 'qty' => 2],
                ['product_variant_id' => $v2->id, 'qty' => 2],
            ],
        ]);
        $quotation = $service->submit($quotation);

        // Pricing only v1 while v2 has 0.00 must be rejected.
        try {
            $service->price($quotation, [
                'priced_by' => $this->admin->id,
                'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
                'items' => [
                    ['id' => $quotation->items->firstWhere('product_variant_id', $v1->id)->id, 'offered_unit_price' => 100],
                ],
            ]);
            $this->fail('Expected BusinessException when a quotation item has 0.00 price.');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }

        // Price both items with positive prices.
        $quotation = $service->price($quotation->fresh(), [
            'priced_by' => $this->admin->id,
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'items' => [
                ['id' => $quotation->items->firstWhere('product_variant_id', $v1->id)->id, 'offered_unit_price' => 100],
                ['id' => $quotation->items->firstWhere('product_variant_id', $v2->id)->id, 'offered_unit_price' => 50],
            ],
        ]);

        // Deactivate v2 before accept -> accept must reject.
        $v2->update(['is_active' => false]);
        try {
            $service->accept($quotation->fresh());
            $this->fail('Expected BusinessException when variant became inactive before accept.');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }

        // Reactivate v2 and accept -> order must have guest_name & guest_phone (exposed as recipient_name/recipient_phone).
        $v2->update(['is_active' => true]);
        $converted = $service->accept($quotation->fresh());
        $this->assertSame($this->trader->name, $converted->convertedOrder->guest_name);
        $this->assertSame($this->trader->phone, $converted->convertedOrder->guest_phone);
    }

    public function test_f13_f14_repricing_priced_quotation_increments_revision_and_stale_accept_returns_409(): void
    {
        $v1 = $this->createVariant('REV-1', 100);
        $service = app(QuotationService::class);
        $quotation = $service->create($this->trader, [
            'items' => [['product_variant_id' => $v1->id, 'qty' => 2]],
        ]);
        $quotation = $service->submit($quotation);
        $quotation = $service->price($quotation, [
            'priced_by' => $this->admin->id,
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'items' => [['id' => $quotation->items->first()->id, 'offered_unit_price' => 100]],
        ]);
        $firstRevision = (int) $quotation->revision;

        // F13: Re-pricing PricedByAdmin must succeed and bump revision.
        $repriced = $service->price($quotation->fresh(), [
            'priced_by' => $this->admin->id,
            'items' => [['id' => $quotation->items->first()->id, 'offered_unit_price' => 120]],
        ]);
        $this->assertSame(QuotationStatus::PricedByAdmin, $repriced->status);
        $this->assertSame($firstRevision + 1, (int) $repriced->revision);

        // F14: Accepting with stale expected_revision must return 409 Conflict.
        $token = $this->trader->createToken('portal')->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept", [
                'expected_revision' => $firstRevision,
            ])
            ->assertStatus(409);

        // Accepting with current revision succeeds.
        $this->withToken($token)
            ->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept", [
                'expected_revision' => $repriced->revision,
            ])
            ->assertOk();
    }

    public function test_f11_cancelled_orders_are_excluded_from_invoices_dashboard_and_pdf(): void
    {
        $activeCredit = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $this->trader->id,
            'status' => OrderStatus::Confirmed,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => 500,
        ]);
        $cancelledCredit = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $this->trader->id,
            'status' => OrderStatus::Cancelled,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => 900,
        ]);

        $token = $this->trader->createToken('portal')->plainTextToken;

        $invIds = collect($this->withToken($token)->getJson('/api/v1/wholesale/invoices')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertContains($activeCredit->id, $invIds);
        $this->assertNotContains($cancelledCredit->id, $invIds);

        $dashIds = collect($this->withToken($token)->getJson('/api/v1/wholesale/dashboard')->assertOk()->json('data.latest_invoices'))
            ->pluck('id')->all();
        $this->assertContains($activeCredit->id, $dashIds);
        $this->assertNotContains($cancelledCredit->id, $dashIds);

        $this->withToken($token)->get("/api/v1/wholesale/invoices/{$cancelledCredit->id}/pdf")
            ->assertNotFound();
    }

    public function test_f12_orders_index_supports_returnable_filter_and_per_page_100(): void
    {
        $returnable = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $this->trader->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => 500,
        ]);
        $alreadyReturned = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $this->trader->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => 600,
        ]);
        ReturnRequest::query()->create([
            'return_number' => 'RET-EXCL-1',
            'order_id' => $alreadyReturned->id,
            'customer_id' => $this->trader->id,
            'status' => ReturnStatus::Pending->value,
            'reason' => 'test',
            'total_refund_amount' => 100,
        ]);

        $token = $this->trader->createToken('portal')->plainTextToken;
        $ids = collect($this->withToken($token)->getJson('/api/v1/wholesale/orders?group=delivered&returnable=1&per_page=100')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$returnable->id], $ids);
    }

    public function test_f16_quick_order_lookup_is_case_insensitive_and_matches_numeric_sku_via_barcode_field(): void
    {
        $this->createVariant('SPH-101', 100);
        $this->createVariant('12345678', 150, null);

        $service = app(WholesaleQuickOrderService::class);
        $results = $service->lookup($this->trader, [
            ['sku' => 'sph-101', 'qty' => 2],
            ['barcode' => '12345678', 'qty' => 3],
        ]);

        $this->assertTrue($results[0]['found']);
        $this->assertSame('SPH-101', $results[0]['sku']);
        $this->assertTrue($results[1]['found']);
        $this->assertSame('12345678', $results[1]['sku']);
    }

    public function test_f19_f23_customer_specific_price_list_flushes_catalog_context_and_export_omits_x_row_count(): void
    {
        $variant = $this->createVariant('PL-100', 200);
        $pricing = app(PricingService::class);
        $priceListService = app(PriceListService::class);

        // Prime in-memory catalog context cache while customer has no specific list.
        $initial = $pricing->resolve($variant, $this->trader, 1);
        $this->assertSame(200.0, (float) $initial['unit_price']);

        $list = $priceListService->create([
            'name' => 'قائمة خاصة للتاجر',
            'type' => PriceListType::CustomerSpecific->value,
            'customer_id' => $this->trader->id,
            'currency' => 'EGP',
            'priority' => 100,
            'is_active' => true,
        ], $this->admin);
        $priceListService->createItem($list, [
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => 140,
        ], $this->admin);

        // Must immediately resolve the new 140 price without manual flush.
        $updated = $pricing->resolve($variant, $this->trader->fresh(), 1);
        $this->assertSame(140.0, (float) $updated['unit_price']);

        // F23: exportCsv must not emit X-Row-Count header.
        $response = $priceListService->exportCsv($list, $this->admin);
        $this->assertFalse($response->headers->has('X-Row-Count'));
    }
}
