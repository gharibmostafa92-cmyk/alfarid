<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2ExecutionSlice5PerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_wholesale_tools_enabled',
            'ai_finance_tools_enabled',
            'ai_pricing_tools_enabled',
            'ai_promotions_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_aging_report_aggregates_buckets_accurately_in_sql(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'inventory.all_branches', 'payment_notices.view']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);

        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'grand_total' => '100.00',
            'due_at' => now()->subDays(10),
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'grand_total' => '250.00',
            'due_at' => now()->subDays(95),
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'aging_report',
            'args' => [],
        ]);

        $this->assertSame('data', $outcome->type);
        $row = $outcome->data['rows'][0];
        $this->assertSame($customer->id, $row['customer_id']);
        $this->assertSame(100.0, $row['buckets']['0_30']);
        $this->assertSame(250.0, $row['buckets']['over_90']);
        $this->assertSame(350.0, $row['total']);
    }

    public function test_margin_risk_report_caches_result_and_avoids_per_variant_queries(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.pricing', 'inventory.all_branches', 'price_lists.view', 'coupons.view', 'inventory.view_cost']);
        $branch = Branch::factory()->create(['is_active' => true]);
        $cat = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['category_id' => $cat->id, 'brand_id' => $brand->id, 'status' => \App\Enums\Catalog\ProductStatus::Published]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'cost_price' => 100, 'retail_price' => 110]);
        Coupon::create(['code' => 'BIG20', 'type' => 'percentage', 'value' => 20, 'is_active' => true]);

        $conv = $this->conv($actor);
        $first = app(ToolRegistry::class)->dispatch($actor, $conv, ['name' => 'margin_risk_report', 'args' => ['branch_id' => $branch->id]]);
        $this->assertSame('data', $first->type, (string) $first->message);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $second = app(ToolRegistry::class)->dispatch($actor, $conv, ['name' => 'margin_risk_report', 'args' => ['branch_id' => $branch->id]]);
        $this->assertSame('data', $second->type);
        // Cached second call should not query price_list_items / product_variants again
        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_customer_account_360_avoids_n_plus_1_queries_for_paid_orders(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'inventory.all_branches', 'payment_notices.view']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);

        for ($i = 1; $i <= 8; $i++) {
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'payment_method' => 'credit',
                'payment_status' => 'paid',
                'status' => OrderStatus::Confirmed,
            ]);
            WholesalePaymentNotice::create([
                'notice_number' => "WPN-360-{$i}",
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'amount' => '100.00',
                'payment_method' => 'bank_transfer',
                'bank_name' => 'CIB',
                'reference_number' => "REF-360-{$i}",
                'transfer_date' => now()->toDateString(),
                'receipt_file_path' => 'r.jpg',
                'status' => WholesalePaymentNoticeStatus::Approved,
                'approved_at' => now(),
            ]);
        }

        $approvalAggregationQueries = 0;
        DB::listen(function ($q) use (&$approvalAggregationQueries): void {
            if (str_contains(strtolower($q->sql), 'wholesale_payment_notices')
                && str_contains(strtolower($q->sql), 'max(')
                && str_contains(strtolower($q->sql), 'approved_at')) {
                $approvalAggregationQueries++;
            }
        });

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'customer_account_360',
            'args' => ['customer_id' => $customer->id],
        ]);
        $this->assertSame('data', $outcome->type);
        $this->assertLessThanOrEqual(2, $approvalAggregationQueries, 'CustomerAccount360Tool must aggregate approval dates in batches, not per order.');
    }

    public function test_get_quotation_batches_pricing_context_for_all_lines(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'inventory.all_branches', 'quotations.view']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $quotation = Quotation::create([
            'quotation_number' => 'QT-N1-1',
            'customer_id' => $customer->id,
            'status' => QuotationStatus::Requested,
        ]);
        for ($i = 1; $i <= 8; $i++) {
            $variant = ProductVariant::factory()->create(['is_active' => true, 'retail_price' => 200, 'wholesale_price' => 150]);
            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'product_variant_id' => $variant->id,
                'qty' => $i,
                'offered_unit_price' => 140,
            ]);
        }

        $priceListItemQueries = 0;
        DB::listen(function ($query) use (&$priceListItemQueries): void {
            if (str_contains(strtolower($query->sql), 'price_list_items')) {
                $priceListItemQueries++;
            }
        });

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'get_quotation',
            'args' => ['quotation_id' => $quotation->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(8, $outcome->data['lines']);
        $this->assertLessThanOrEqual(1, $priceListItemQueries, 'GetQuotationTool must preload pricing and tiers for all quotation variants.');
    }

    public function test_expense_anomalies_runs_cleanly_with_batched_queries(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.finance', 'inventory.all_branches', 'expenses.view', 'finance.reports.view']);
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = Treasury::create(['name' => 'خزينة', 'code' => 'TR-EX-1', 'type' => \App\Enums\Finance\TreasuryType::MainSafe, 'branch_id' => $branch->id, 'is_active' => true]);

        for ($group = 1; $group <= 3; $group++) {
            for ($i = 1; $i <= 2; $i++) {
                Expense::create([
                    'expense_number' => "EXP-A-{$group}-{$i}",
                    'branch_id' => $branch->id,
                    'treasury_id' => $treasury->id,
                    'category' => 'نقل-'.$group,
                    'amount' => (string) (1000 * $group).'.00',
                    'expense_date' => now()->toDateString(),
                    'created_by' => $actor->id,
                ]);
            }
        }

        $expenseQueries = 0;
        DB::listen(function ($query) use (&$expenseQueries): void {
            if (str_contains(strtolower($query->sql), 'expenses')) {
                $expenseQueries++;
            }
        });
        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'expense_anomalies',
            'args' => [],
        ]);
        $this->assertSame('data', $outcome->type);
        $this->assertLessThanOrEqual(8, $expenseQueries, 'Duplicate-group details and first expense numbers must be batched.');
    }

    public function test_upsert_price_list_shadow_warnings_use_one_competing_list_query(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.pricing', 'inventory.all_branches', 'price_lists.view', 'price_lists.update']);
        $target = PriceList::create(['name' => 'PL-IMPORT', 'type' => 'retail', 'is_active' => true, 'priority' => 1]);
        $competitor = PriceList::create(['name' => 'PL-WINNER', 'type' => 'retail', 'is_active' => true, 'priority' => 5]);
        $unrelated = PriceList::create(['name' => 'PL-WHOLESALE', 'type' => 'wholesale', 'is_active' => true, 'priority' => 99]);
        $expired = PriceList::create(['name' => 'PL-EXPIRED', 'type' => 'retail', 'is_active' => true, 'priority' => 99]);
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 100, 'retail_price' => 200]);
            $rows[] = ['variant_id' => $variant->id, 'min_qty' => 1, 'price' => 150];
            if ($i === 1) {
                PriceListItem::create(['price_list_id' => $competitor->id, 'product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 140]);
                PriceListItem::create(['price_list_id' => $unrelated->id, 'product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 130]);
                PriceListItem::create([
                    'price_list_id' => $expired->id,
                    'product_variant_id' => $variant->id,
                    'min_qty' => 1,
                    'price' => 120,
                    'starts_at' => now()->subDays(2),
                    'ends_at' => now()->subDay(),
                ]);
            }
        }

        $shadowQueries = 0;
        DB::listen(function ($query) use (&$shadowQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'price_list_items') && str_contains($sql, 'price_lists') && str_contains($sql, 'join')) {
                $shadowQueries++;
            }
        });

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $target->id, 'rows' => $rows],
        ]);

        $this->assertSame('proposal', $outcome->type, (string) $outcome->message);
        $this->assertSame(1, $shadowQueries, 'All imported variants must be checked against competing lists in one query.');
        $warnings = $outcome->uiBlock['warnings'] ?? [];
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('PL-WINNER', $warnings[0]);
    }
}
