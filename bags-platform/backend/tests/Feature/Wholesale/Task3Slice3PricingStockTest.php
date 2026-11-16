<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Catalog\PriceListType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\SalesChannel;
use App\Imports\WholesaleQuickOrderImport;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Pricing\PriceListService;
use App\Services\Pricing\PricingService;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\QuoteAdvisor;
use App\Services\Wholesale\WholesalePriceListService;
use App\Services\Wholesale\WholesaleQuickOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Concerns\WithColumnLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Tests\TestCase;

class Task3Slice3PricingStockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;
    private User $admin;
    private Branch $activeBranch;
    private Branch $inactiveBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->activeBranch = Branch::factory()->create(['name' => 'فرع رئيسي نشط', 'is_active' => true]);
        $this->inactiveBranch = Branch::factory()->create(['name' => 'مستودع جرد معطل', 'is_active' => false]);

        $this->customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
    }

    public function test_high02_inventory_availability_strictly_excludes_inactive_branches(): void
    {
        $product = Product::factory()->create([
            'sales_channel' => SalesChannel::Wholesale,
            'status' => 'published',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'sku' => 'STOCK-UNIFIED-01',
            'wholesale_price' => '100.00',
            'retail_price' => '150.00',
        ]);

        // 10 units in active branch
        StockLevel::forceCreate([
            'branch_id' => $this->activeBranch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 0,
            'damaged' => 0,
        ]);

        // 50 units in inactive branch (must NOT be counted)
        StockLevel::forceCreate([
            'branch_id' => $this->inactiveBranch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
            'reserved' => 0,
            'damaged' => 0,
        ]);

        // 1. WholesaleQuickOrderService::lookup
        $quickService = app(WholesaleQuickOrderService::class);
        $search = $quickService->lookup($this->customer, [['sku' => 'STOCK-UNIFIED-01', 'qty' => 1]]);
        $this->assertCount(1, $search);
        $this->assertEquals(10.0, (float) $search[0]['available_qty'], 'QuickOrder lookup must only count active branch stock');

        // 2. WholesalePriceListService::rows
        $priceListService = app(WholesalePriceListService::class);
        $rows = $priceListService->rows($this->customer);
        $row = collect($rows)->firstWhere('sku', 'STOCK-UNIFIED-01');
        $this->assertNotNull($row);
        $this->assertEquals(10.0, (float) $row['available_qty'], 'PriceList rows must only count active branch stock');

        // 3. QuotationService::acceptanceWarnings (requesting 25 units when only 10 active units exist)
        $quoteService = app(QuotationService::class);
        $quotation = $quoteService->create($this->customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 25]],
        ]);
        $warnings = $quoteService->acceptanceWarnings($quotation);
        $this->assertNotEmpty($warnings, 'Quotation acceptanceWarnings must warn when requested exceeds active branch stock');
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'متاح منه 10.00')), 'Warning message must specify 10.00 available units');

        // 4. QuoteAdvisor::suggest
        $advisor = app(QuoteAdvisor::class);
        $advice = $advisor->suggest($quotation, $this->admin);
        $this->assertEquals(10.0, (float) $advice['lines'][0]['stock_available'], 'QuoteAdvisor must only count active branch stock');
    }

    public function test_high03_price_list_update_dispatches_catalog_retail_prices_refresh(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $priceList = PriceList::create([
            'name' => 'قائمة قطاعي عامة',
            'type' => PriceListType::Retail,
            'currency' => 'EGP',
            'is_active' => true,
            'priority' => 1,
        ]);

        PriceListItem::create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => '120.00',
        ]);

        Queue::fake();

        $service = app(PriceListService::class);

        // Update is_active to false
        $service->update($priceList, ['is_active' => false], $this->admin);

        Queue::assertPushed(RefreshCatalogRetailPrices::class, function ($job) use ($product) {
            return in_array($product->id, (array) $job->productId, true);
        });
    }

    public function test_high03_pricing_context_ignores_inactive_customer_specific_price_lists(): void
    {
        $inactiveList = PriceList::create([
            'name' => 'قائمة خاصة غير نشطة',
            'type' => PriceListType::CustomerSpecific,
            'customer_id' => $this->customer->id,
            'currency' => 'EGP',
            'is_active' => false,
            'priority' => 10,
        ]);

        $pricing = app(PricingService::class);
        $context = $pricing->catalogContext($this->customer);

        // If customer has only inactive customer-specific price list, the context key should NOT assume active customer specific pricing
        $this->assertStringNotContainsString('customer_specific', $context['key']);

        $pricing->flushCatalogContexts();
    }

    public function test_high05_quick_order_import_enforces_multiple_sheets_and_column_limit(): void
    {
        $import = new WholesaleQuickOrderImport;

        $this->assertInstanceOf(WithMultipleSheets::class, $import);
        $this->assertInstanceOf(WithColumnLimit::class, $import);

        $sheets = $import->sheets();
        $this->assertArrayHasKey(0, $sheets);
        $this->assertSame($import, $sheets[0]);

        $this->assertSame('D', $import->endColumn());
    }
}
