<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Services\Wholesale\QuotationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QuotationOrderHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 50000,
        ]);
        $this->withToken($this->trader->createToken('portal')->plainTextToken);

        $product = Product::factory()->published()->create(['sales_channel' => 'both']);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 120, 'wholesale_price' => 100, 'is_active' => true]);
    }

    public function test_fractional_quantities_are_rejected_when_creating_a_quote(): void
    {
        $this->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1.5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
    }

    public function test_fractional_quantities_are_rejected_when_editing_a_draft(): void
    {
        $quote = $this->draft(2);

        $this->putJson("/api/v1/wholesale/quotations/{$quote->id}", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2.5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
    }

    public function test_service_does_not_create_fractional_quote_items(): void
    {
        $this->expectException(BusinessException::class);

        app(QuotationService::class)->create($this->trader, [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1.5]],
        ]);
    }

    public function test_preexisting_fractional_quote_cannot_be_converted(): void
    {
        $this->stock(5);
        $quote = $this->priced();
        $quote->items[0]->update(['qty' => '1.50', 'line_total' => '150.00']);
        $quote->update(['subtotal' => '150.00', 'grand_total' => '150.00']);

        $this->postJson("/api/v1/wholesale/quotations/{$quote->id}/accept")
            ->assertUnprocessable();

        $this->assertDatabaseMissing('orders', ['customer_id' => $this->trader->id]);
    }

    public function test_duplicate_variant_rows_use_the_combined_quantity_tier(): void
    {
        $list = PriceList::query()->create([
            'name' => 'Wholesale quantity tier',
            'type' => 'wholesale',
            'currency' => 'EGP',
            'priority' => 1,
            'is_active' => true,
        ]);
        $list->items()->create(['product_variant_id' => $this->variant->id, 'min_qty' => 10, 'price' => 80]);

        $quote = $this->draft(6, 5);

        $this->assertSame(['80.00', '80.00'], $quote->items->pluck('offered_unit_price')->all());
        $this->assertSame('880.00', $quote->grand_total);
    }

    public function test_stock_warning_checks_the_combined_demand_for_a_variant(): void
    {
        $this->stock(9);
        $quote = $this->draft(6, 5);

        $warnings = app(QuotationService::class)->acceptanceWarnings($quote);

        $this->assertStringContainsString('المطلوب 11.00', implode(' ', $warnings));
    }

    public function test_conversion_snapshots_the_default_address_and_customer_contact(): void
    {
        $this->stock(5);
        $quote = $this->priced();
        $default = $this->trader->addresses()->firstOrFail();
        $this->trader->addresses()->create([
            'city_id' => City::factory()->create(['shipping_cost' => '15.45'])->id,
            'address_line' => 'Warehouse 12',
            'is_default' => false,
        ]);

        $orderId = $this->postJson("/api/v1/wholesale/quotations/{$quote->id}/accept")
            ->assertOk()
            ->json('data.converted_order_id');

        $this->getJson("/api/v1/wholesale/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.recipient_name', $this->trader->name)
            ->assertJsonPath('data.recipient_phone', $this->trader->phone)
            ->assertJsonPath('data.city_id', $default->city_id)
            ->assertJsonPath('data.shipping_address_line', $default->address_line);
    }

    public function test_conversion_charges_shipping_in_the_order_total(): void
    {
        $this->stock(5);
        $quote = $this->priced();
        $address = $this->trader->addresses()->firstOrFail();
        $address->city->update(['shipping_cost' => '25.45']);

        $orderId = $this->postJson("/api/v1/wholesale/quotations/{$quote->id}/accept")
            ->assertOk()
            ->json('data.converted_order_id');

        $this->getJson("/api/v1/wholesale/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.shipping_cost', '25.45')
            ->assertJsonPath('data.grand_total', '125.45');
    }

    public function test_shipping_can_put_a_conversion_over_its_credit_limit(): void
    {
        $this->stock(5);
        $this->trader->update(['credit_limit' => '125.44']);
        $this->trader->addresses()->firstOrFail()->city->update(['shipping_cost' => '25.45']);
        $quote = $this->priced();

        $this->postJson("/api/v1/wholesale/quotations/{$quote->id}/accept")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'credit_limit_exceeded');

        $this->assertSame(QuotationStatus::PricedByAdmin, $quote->fresh()->status);
        $this->assertDatabaseMissing('orders', ['customer_id' => $this->trader->id]);
    }

    public function test_conversion_and_subsequent_reads_include_the_commercial_order_number(): void
    {
        $this->stock(5);
        $quote = $this->priced();

        $response = $this->postJson("/api/v1/wholesale/quotations/{$quote->id}/accept")->assertOk();
        $order = Order::query()->findOrFail($response->json('data.converted_order_id'));
        $response->assertJsonPath('data.converted_order_number', $order->order_number);
        $this->getJson("/api/v1/wholesale/quotations/{$quote->id}")
            ->assertOk()->assertJsonPath('data.converted_order_number', $order->order_number);
        $this->getJson('/api/v1/wholesale/quotations')
            ->assertOk()->assertJsonPath('data.0.converted_order_number', $order->order_number);
    }

    public function test_pricing_totals_reconcile_with_the_stored_decimal_unit_price(): void
    {
        $service = app(QuotationService::class);
        $quote = $service->submit($this->draft(3));
        $priced = $service->price($quote, [
            'items' => [['id' => $quote->items[0]->id, 'offered_unit_price' => '1.005']],
            'discount_amount' => '0.02',
            'tax_amount' => '0.01',
        ]);

        $this->assertSame('1.01', $priced->items[0]->offered_unit_price);
        $this->assertSame('3.03', $priced->items[0]->line_total);
        $this->assertSame('3.02', $priced->grand_total);
    }

    public function test_repricing_can_explicitly_clear_the_expiry_and_staff_notes(): void
    {
        $service = app(QuotationService::class);
        $quote = $service->price($service->submit($this->draft(1)), [
            'admin_notes' => 'Initial note',
            'valid_until' => now()->addDays(4)->toDateString(),
        ]);

        $cleared = $service->price($quote, ['admin_notes' => null, 'valid_until' => null]);

        $this->assertNull($cleared->admin_notes);
        $this->assertNull($cleared->valid_until);
    }

    public function test_pricing_rejects_repeated_line_ids_at_the_service_boundary(): void
    {
        $service = app(QuotationService::class);
        $quote = $service->submit($this->draft(1));
        $itemId = $quote->items[0]->id;

        $this->expectException(BusinessException::class);
        $service->price($quote, ['items' => [
            ['id' => $itemId, 'offered_unit_price' => 70],
            ['id' => (string) $itemId, 'offered_unit_price' => 60],
        ]]);
    }

    private function draft(int ...$quantities): Quotation
    {
        return app(QuotationService::class)->create($this->trader, [
            'items' => array_map(fn (int $qty) => ['product_variant_id' => $this->variant->id, 'qty' => $qty], $quantities),
        ]);
    }

    private function priced(): Quotation
    {
        $service = app(QuotationService::class);

        return $service->price($service->submit($this->draft(1)), []);
    }

    private function stock(int $qty): void
    {
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => $qty,
        ]);
    }
}
