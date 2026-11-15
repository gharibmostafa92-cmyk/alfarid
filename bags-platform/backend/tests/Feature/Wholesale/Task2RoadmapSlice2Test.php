<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\SalesChannel;
use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Imports\WholesaleQuickOrderImport;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\QuoteAdvisor;
use App\Services\Wholesale\WholesaleInsights;
use App\Services\Wholesale\WholesalePriceListService;
use App\Services\Wholesale\WholesaleQuickOrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithLimit;
use Tests\TestCase;

class Task2RoadmapSlice2Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    private function wholesaleCustomer(float $creditLimit = 100000): Customer
    {
        return Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => $creditLimit,
        ]);
    }

    private function makeVariant(SalesChannel $channel, ProductStatus $status = ProductStatus::Published, bool $activeCategory = true, string $sku = ''): ProductVariant
    {
        $category = Category::factory()->create(['is_active' => $activeCategory]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'status' => $status,
            'sales_channel' => $channel,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => $sku !== '' ? $sku : 'SKU-'.uniqid(),
            'is_active' => true,
            'retail_price' => 100,
            'wholesale_price' => 80,
        ]);
    }

    // HIGH-01: Wholesale purchasable scope enforced across Quotation, QuickOrder, PriceList export, and WhatsApp Assistant
    public function test_wholesale_services_reject_retail_only_and_draft_variants(): void
    {
        $customer = $this->wholesaleCustomer();
        $retailVariant = $this->makeVariant(SalesChannel::Retail, ProductStatus::Published, true, 'RET-ONLY-1');
        $draftVariant = $this->makeVariant(SalesChannel::Wholesale, ProductStatus::Draft, true, 'DRAFT-ONLY-1');
        $wholesaleVariant = $this->makeVariant(SalesChannel::Wholesale, ProductStatus::Published, true, 'WHL-OK-1');

        // 1. QuotationService::create rejects retail-only variant
        try {
            app(QuotationService::class)->create($customer, [
                'items' => [['product_variant_id' => $retailVariant->id, 'qty' => 5]],
            ]);
            $this->fail('Expected BusinessException for retail-only variant in quotation.');
        } catch (BusinessException) {
            $this->assertTrue(true);
        }

        // 2. WholesaleQuickOrderService::lookup skips retail-only and draft variants
        $lookup = app(WholesaleQuickOrderService::class)->lookup($customer, [
            ['sku' => 'RET-ONLY-1', 'qty' => 2],
            ['sku' => 'DRAFT-ONLY-1', 'qty' => 2],
            ['sku' => 'WHL-OK-1', 'qty' => 2],
        ]);
        $bySku = collect($lookup)->keyBy('code');
        $this->assertFalse($bySku['RET-ONLY-1']['found']);
        $this->assertFalse($bySku['DRAFT-ONLY-1']['found']);
        $this->assertTrue($bySku['WHL-OK-1']['found']);

        // 3. WholesalePriceListService::rows excludes retail-only and draft variants
        $skus = collect(app(WholesalePriceListService::class)->rows($customer))->pluck('sku')->all();
        $this->assertContains('WHL-OK-1', $skus);
        $this->assertNotContains('RET-ONLY-1', $skus);
        $this->assertNotContains('DRAFT-ONLY-1', $skus);

        // 4. CustomerWholesaleAssistant::searchCustomerVariants excludes retail-only on exact SKU
        $assistant = app(CustomerWholesaleAssistant::class);
        $method = new \ReflectionMethod($assistant, 'searchCustomerVariants');
        $method->setAccessible(true);
        $this->assertSame([], $method->invoke($assistant, 'RET-ONLY-1'));
        $this->assertNotEmpty($method->invoke($assistant, 'WHL-OK-1'));
    }

    // HIGH-02: QuotationService re-verifies status under DB lock (stale in-memory status fails)
    public function test_quotation_service_rechecks_status_from_db_under_lock(): void
    {
        $customer = $this->wholesaleCustomer();
        $variant = $this->makeVariant(SalesChannel::Wholesale);
        $quotation = app(QuotationService::class)->create($customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 2]],
        ]);

        // Simulate stale in-memory model where DB row was already submitted concurrently
        $stale = clone $quotation;
        Quotation::query()->whereKey($quotation->id)->update(['status' => QuotationStatus::Requested->value]);

        $this->expectException(BusinessException::class);
        app(QuotationService::class)->submit($stale);
    }

    public function test_accepting_a_stale_expired_quotation_cannot_overwrite_a_newer_state(): void
    {
        $customer = $this->wholesaleCustomer();
        $variant = $this->makeVariant(SalesChannel::Wholesale);
        $quotation = app(QuotationService::class)->create($customer, ['items' => [['product_variant_id' => $variant->id, 'qty' => 2]]]);
        $quotation->update(['status' => QuotationStatus::PricedByAdmin, 'valid_until' => now()->subDay()]);
        $stale = $quotation->fresh();
        Quotation::query()->whereKey($quotation->id)->update(['status' => QuotationStatus::Rejected->value]);
        try {
            app(QuotationService::class)->accept($stale);
            $this->fail('A stale quotation cannot be accepted.');
        } catch (BusinessException) {
            $this->assertSame(QuotationStatus::Rejected, $quotation->fresh()->status);
        }
    }

    // HIGH-06: QuotationService::accept falls back to priced_by when no is_system user exists
    public function test_quotation_accept_falls_back_to_priced_by_when_system_user_missing(): void
    {
        User::query()->where('is_system', true)->update(['is_system' => false]);

        $branch = Branch::factory()->create(['is_active' => true]);
        $pricer = User::factory()->create(['branch_id' => $branch->id, 'is_system' => false]);
        $customer = $this->wholesaleCustomer();
        $city = \App\Models\City::factory()->create();
        $customer->addresses()->create(['city_id' => $city->id, 'address_line' => 'شارع الاختبار', 'is_default' => true]);
        $variant = $this->makeVariant(SalesChannel::Wholesale);
        StockLevel::forceCreate([
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
            'reserved' => 0,
        ]);

        $service = app(QuotationService::class);
        $quotation = $service->create($customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 5]],
        ]);
        $quotation = $service->submit($quotation);
        $quotation = $service->price($quotation, [
            'priced_by' => $pricer->id,
            'valid_until' => now()->addDays(7)->toDateString(),
            'discount_amount' => 25,
            'tax_amount' => 10,
            'items' => [['id' => $quotation->items->first()->id, 'offered_unit_price' => 75]],
        ]);

        $accepted = $service->accept($quotation);
        $this->assertSame(QuotationStatus::ConvertedToOrder, $accepted->status);
        $this->assertNotNull($accepted->converted_order_id);
        $line = $accepted->convertedOrder->items()->firstOrFail();
        $this->assertSame('360.00', $line->line_total);
        $this->assertSame($city->id, $accepted->convertedOrder->city_id);
        $this->assertSame('شارع الاختبار', $accepted->convertedOrder->shipping_address_line);
    }

    // HIGH-07 & MEDIUM-09: QuickOrder import enforces WithLimit, 500-row cap, and RejectExecutableSignature
    public function test_quick_order_import_limits_and_executable_signature_rejection(): void
    {
        $import = new WholesaleQuickOrderImport;
        $this->assertInstanceOf(WithLimit::class, $import);
        $this->assertLessThanOrEqual(1000, $import->limit());

        $customer = $this->wholesaleCustomer();
        Auth::forgetGuards();
        $token = $customer->createToken('wholesale')->plainTextToken;

        $exeFile = UploadedFile::fake()->createWithContent('evil.csv', "MZ\x90\x00\x03\x00");
        $this->withToken($token)
            ->postJson('/api/v1/wholesale/quick-order/import', ['file' => $exeFile])
            ->assertUnprocessable();

        $lines = ["sku,qty"];
        for ($i = 1; $i <= 505; $i++) {
            $lines[] = "SKU-{$i},1";
        }
        $bigCsv = UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines));
        $this->withToken($token)
            ->postJson('/api/v1/wholesale/quick-order/import', ['file' => $bigCsv])
            ->assertStatus(422);
    }

    // MEDIUM-04: QuoteAdvisor & WholesaleInsights count ConvertedToOrder as accepted
    public function test_quote_acceptance_metrics_include_converted_to_order(): void
    {
        $customer = $this->wholesaleCustomer();
        $variant = $this->makeVariant(SalesChannel::Wholesale);

        $quotation = Quotation::query()->create([
            'quotation_number' => 'QT-CONV-1',
            'customer_id' => $customer->id,
            'status' => QuotationStatus::ConvertedToOrder,
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        $quotation->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 2,
            'offered_unit_price' => 50,
            'line_total' => 100,
        ]);

        $insights = app(WholesaleInsights::class)->quoteAcceptance();
        $this->assertSame(1, $insights['accepted']);
        $this->assertSame(1, $insights['closed']);
        $this->assertSame(100.0, $insights['acceptance_rate']);

        $advisor = app(QuoteAdvisor::class);
        $method = new \ReflectionMethod($advisor, 'batchAcceptanceRates');
        $method->setAccessible(true);
        $rates = $method->invoke($advisor, $customer->id, [$variant->id]);
        $this->assertSame(100.0, $rates[$variant->id]);
    }
}
