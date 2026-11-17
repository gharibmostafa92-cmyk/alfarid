<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Catalog\ProductStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Orders\SalesChannel;
use App\Enums\Catalog\PriceListType;
use App\Exports\WholesalePriceListExport;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Customer\CustomerGroupService;
use App\Services\Pricing\PriceListService;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\Wholesale\WholesaleQuickOrderService;
use App\Services\Wholesale\WholesaleRegistrationService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Tests\TestCase;

class Task2Slice4ServicesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_quick_order_import_rejects_xls_extension(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
        Auth::forgetGuards();
        $token = $customer->createToken('wholesale')->plainTextToken;

        $xls = UploadedFile::fake()->create('orders.xls', 10, 'application/vnd.ms-excel');
        $this->withToken($token)
            ->postJson('/api/v1/wholesale/quick-order/import', ['file' => $xls])
            ->assertStatus(422);
    }

    public function test_price_list_apply_percentage_skips_full_before_dump_over_100_items_and_item_ops_refresh_retail_prices(): void
    {
        Bus::fake([RefreshCatalogRetailPrices::class]);

        $admin = User::factory()->create(['branch_id' => null]);
        $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('inventory.all_branches', 'staff'));
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Both]);

        $retailList = PriceList::create([
            'name' => 'Retail Promo',
            'type' => PriceListType::Retail,
            'priority' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        $svc = app(PriceListService::class);
        $item = $svc->createItem($retailList, ['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 100], $admin);
        Bus::assertDispatched(RefreshCatalogRetailPrices::class);

        // Create 105 items and apply percentage -> audit log must not dump 105 items in old_values['items'].
        $variants = ProductVariant::factory()->count(105)->create(['product_id' => $product->id, 'is_active' => true]);
        $rows = [];
        foreach ($variants as $v) {
            $rows[] = [
                'price_list_id' => $retailList->id,
                'product_variant_id' => $v->id,
                'min_qty' => 1,
                'price' => '50.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('price_list_items')->insert($rows);

        $svc->applyPercentage($retailList, 10, $admin);
        $log = DB::table('staff_audit_logs')->where('action', 'price_list.percentage_applied')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $oldValues = json_decode((string) ($log->old_values ?? '{}'), true);
        $this->assertArrayNotHasKey('items', $oldValues ?? [], 'Bulk applyPercentage > 100 items must not dump all items into audit_logs.');
    }

    public function test_wholesale_price_list_export_uses_string_value_binder_and_escapes_pipe_formula(): void
    {
        $export = new WholesalePriceListExport([]);
        $this->assertInstanceOf(WithCustomValueBinder::class, $export);
        $this->assertSame("'|cmd", WholesalePriceListExport::cellText('|cmd'));
    }

    public function test_bank_statement_matcher_skips_unapproved_customers_and_matches_approved_customer(): void
    {
        Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Pending,
            'company_name' => 'Alpha Pending Traders',
        ]);
        $approved = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'company_name' => 'Beta Approved Traders',
        ]);

        $res = app(BankStatementMatcher::class)->match([
            ['amount' => '1234.00', 'description' => 'Transfer from Alpha Pending Traders ref 1'],
            ['amount' => '5678.00', 'description' => 'Transfer from Beta Approved Traders ref 2'],
        ]);

        $this->assertNull($res['unmatched'][0]['suggestion'], 'Pending customers must not be suggested.');
        $this->assertSame($approved->id, $res['unmatched'][1]['suggestion']['customer_id']);
    }

    public function test_quick_order_find_variants_separates_sku_and_barcode_namespaces(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale]);

        // Variant A has barcode "SHARED-123"; Variant B has sku "SHARED-123".
        $variantA = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SKU-A-1', 'barcode' => 'SHARED-123', 'is_active' => true]);
        $variantB = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SHARED-123', 'barcode' => 'BAR-B-1', 'is_active' => true]);

        $result = app(WholesaleQuickOrderService::class)->lookup($customer, [
            ['barcode' => 'SHARED-123', 'qty' => 1],
            ['sku' => 'SHARED-123', 'qty' => 1],
        ]);

        $this->assertSame($variantA->id, $result[0]['product_variant_id'], 'Lookup by barcode must resolve variant A.');
        $this->assertSame($variantB->id, $result[1]['product_variant_id'], 'Lookup by sku must resolve variant B.');
    }

    public function test_wholesale_registration_rejects_soft_deleted_phone_before_r2_upload(): void
    {
        Storage::fake('r2');
        $deleted = Customer::factory()->create([
            'phone' => '01012345678',
            'email' => 'deleted-trader@example.com',
        ]);
        $deleted->delete();

        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');
        try {
            app(WholesaleRegistrationService::class)->register([
                'name' => 'New Trader',
                'phone' => '01012345678',
                'email' => 'fresh-trader@example.com',
                'password' => 'Secret123!',
                'company_name' => 'Test Co',
                'tax_number' => '123456789',
                'commercial_register' => '987654',
            ], [$file], ['commercial_register']);
            $this->fail('Expected ValidationException for soft-deleted phone.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
            $this->assertEmpty(Storage::disk('r2')->allFiles(), 'Must not upload files to R2 when soft-deleted phone already exists.');
        }
    }

    public function test_service_pagination_clamps_per_page_to_100(): void
    {
        $admin = User::factory()->create(['branch_id' => null]);
        $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('inventory.all_branches', 'staff'));
        $list = PriceList::create(['name' => 'Clamp Test', 'type' => PriceListType::Wholesale, 'priority' => 1, 'is_active' => true]);

        $p1 = app(PriceListService::class)->paginate(['per_page' => 5000], $admin);
        $p2 = app(PriceListService::class)->paginateItems($list, ['per_page' => 5000], $admin);
        $p3 = app(CustomerGroupService::class)->paginate(['per_page' => 5000]);

        $this->assertSame(100, $p1->perPage());
        $this->assertSame(100, $p2->perPage());
        $this->assertSame(100, $p3->perPage());
    }
}
