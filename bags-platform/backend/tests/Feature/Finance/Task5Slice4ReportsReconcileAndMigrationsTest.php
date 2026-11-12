<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FinanceReconcileRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\ReportService;
use App\Services\Finance\StatementBudget;
use App\Services\Pos\ExpenseService;
use App\Services\Pos\TreasuryService;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class Task5Slice4ReportsReconcileAndMigrationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_f05_split_shipped_order_revenue_is_recognized_once_in_first_sale_month_only(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $variantA = ProductVariant::factory()->create(['cost_price' => '100.00']);
        $variantB = ProductVariant::factory()->create(['cost_price' => '100.00']);

        $order = Order::factory()->create([
            'status' => 'delivered',
            'allocated_branch_id' => $branch->id,
            'subtotal' => '1000.00',
            'discount_amount' => '100.00',
            'tax_amount' => '0.00',
            'shipping_cost' => '0.00',
            'cod_fee' => '0.00',
            'grand_total' => '900.00',
            'created_at' => '2026-01-10 12:00:00',
        ]);
        OrderItem::factory()->forVariant($variantA)->create([
            'order_id' => $order->id, 'allocated_branch_id' => $branch->id,
            'qty' => 1, 'unit_price' => 500, 'discount_amount' => 50, 'line_total' => 450,
        ]);
        OrderItem::factory()->forVariant($variantB)->create([
            'order_id' => $order->id, 'allocated_branch_id' => $branch->id,
            'qty' => 1, 'unit_price' => 500, 'discount_amount' => 50, 'line_total' => 450,
        ]);

        foreach ([
            [$variantA, '2026-01-25 12:00:00'],
            [$variantB, '2026-02-05 12:00:00'],
        ] as [$variant, $ts]) {
            $m = new StockMovement([
                'reference_number' => 'SPLIT-'.Str::uuid(),
                'product_variant_id' => $variant->id,
                'branch_id' => $branch->id,
                'type' => 'sale',
                'affected_bucket' => 'on_hand',
                'quantity' => -1,
                'balance_before' => 10,
                'balance_after' => 9,
                'unit_cost' => '100.00',
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $staff->id,
            ]);
            $m->created_at = $ts;
            $m->save();
        }

        $reports = app(ReportService::class);
        $jan = $reports->profitReport('2026-01-01', '2026-01-31', $branch->id);
        $feb = $reports->profitReport('2026-02-01', '2026-02-28', $branch->id);

        $this->assertSame(900.0, (float) $jan['net_sales_revenue']);
        $this->assertSame(100.0, (float) $jan['cogs']);
        $this->assertSame(0.0, (float) $feb['net_sales_revenue']);
        $this->assertSame(100.0, (float) $feb['cogs']);
    }

    public function test_f06_statement_budget_handles_aborted_sql_and_validates_dates_before_db(): void
    {
        // Invalid dates must be rejected before StatementBudget touches PostgreSQL.
        $this->seed(SettingSeeder::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ReportService::class)->profitReport('2026-02-30', '2026-03-01');
            $this->fail('Invalid calendar date must throw ValidationException.');
        } catch (ValidationException $e) {
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ReportService::class)->salesReport('2026-11-1', '2026-11-05');
            $this->fail('Non-canonical date format must throw ValidationException.');
        } catch (ValidationException $e) {
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        // An aborted SQL query inside StatementBudget must surface the original QueryException (not 25P02 from finally).
        try {
            StatementBudget::run(5000, function (): void {
                DB::select('SELECT * FROM definitely_missing_table_xyz');
            });
            $this->fail('Expected QueryException from invalid SQL.');
        } catch (QueryException $e) {
            $this->assertNotSame('25P02', (string) $e->getCode());
        }
    }

    public function test_f15_inventory_report_shares_single_cached_dataset_between_cost_and_nocost(): void
    {
        Cache::flush();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => '50.00']);
        StockLevel::forceCreate([
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 10,
        ]);

        $reports = app(ReportService::class);
        $blindFirst = $reports->inventoryReport(90, $branch->id, withCosts: false);
        $this->assertArrayNotHasKey('total_value', $blindFirst);
        $this->assertArrayNotHasKey('total_value', $blindFirst['valuation'][0]);
        $this->assertArrayNotHasKey('tied_value', $blindFirst['dead_stock'][0]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $costSecond = $reports->inventoryReport(90, $branch->id, withCosts: true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Cost-visible call must reuse shared cached inventory dataset without re-querying DB.');
        $this->assertSame(500.0, (float) $costSecond['total_value']);
        $this->assertSame(500.0, (float) $costSecond['valuation'][0]['total_value']);
        $this->assertSame(500.0, (float) $costSecond['dead_stock'][0]['tied_value']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $blindAgain = $reports->inventoryReport(90, $branch->id, withCosts: false);
        $queries2 = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries2);
        $this->assertArrayNotHasKey('total_value', $blindAgain);
        $this->assertArrayNotHasKey('total_value', $blindAgain['valuation'][0]);
        $this->assertArrayNotHasKey('tied_value', $blindAgain['dead_stock'][0]);
    }

    public function test_f16_report_service_prevents_float_precision_drift(): void
    {
        $this->seed(SettingSeeder::class);
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'channel' => 'store',
            'status' => 'delivered',
            'allocated_branch_id' => $branch->id,
            'subtotal' => '1000000.10',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'shipping_cost' => '0.00',
            'cod_fee' => '0.00',
            'grand_total' => '1000000.10',
            'created_at' => '2026-06-15 10:00:00',
        ]);
        Order::factory()->create([
            'channel' => 'pos',
            'status' => 'delivered',
            'allocated_branch_id' => $branch->id,
            'subtotal' => '0.20',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'shipping_cost' => '0.00',
            'cod_fee' => '0.00',
            'grand_total' => '0.20',
            'created_at' => CarbonImmutable::now((string) config('app.business_timezone', 'Africa/Cairo')),
        ]);
        $variant = ProductVariant::factory()->create(['cost_price' => '0.00']);
        $m = new StockMovement([
            'reference_number' => 'FLOAT-SALE-1',
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => 'sale',
            'affected_bucket' => 'on_hand',
            'quantity' => -1,
            'balance_before' => 5,
            'balance_after' => 4,
            'unit_cost' => '0.00',
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'created_by' => $staff->id,
        ]);
        $m->created_at = '2026-06-15 10:00:00';
        $m->save();

        $treasury = Treasury::create([
            'name' => 'Big Safe',
            'code' => 'BIG-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);
        DB::table('expenses')->insert([
            'expense_number' => 'EXP-FLOAT-1',
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'category' => 'عريض',
            'amount' => '999999.90',
            'expense_date' => '2026-06-15',
            'created_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profit = app(ReportService::class)->profitReport('2026-06-01', '2026-06-30', $branch->id);
        $this->assertSame('0.2', (string) $profit['net_profit']);
        $this->assertSame(0.2, $profit['net_profit']);
    }

    public function test_f17_suppliers_report_aging_uses_business_timezone_near_midnight(): void
    {
        // 22:30 UTC on 2026-11-12 is 00:30 on 2026-11-13 in Africa/Cairo (+02:00).
        $this->travelTo(CarbonImmutable::parse('2026-11-12 22:30:00', 'UTC'));
        try {
            $supplier = Supplier::factory()->create(['current_balance' => '500.00']);
            SupplierInvoice::factory()->create([
                'supplier_id' => $supplier->id,
                'status' => 'posted',
                'subtotal' => '500.00',
                'tax_amount' => '0.00',
                'total_amount' => '500.00',
                'paid_amount' => '0.00',
                'due_date' => '2026-11-12',
            ]);

            $row = collect(app(ReportService::class)->suppliersReport())
                ->firstWhere('supplier_id', $supplier->id);

            $this->assertSame(500.0, (float) $row['aging']['overdue_1_30']);
            $this->assertSame(0.0, (float) $row['aging']['not_due']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_f11_reconcile_with_branch_id_skips_global_customer_wallets(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::create([
            'name' => 'Branch Safe',
            'code' => 'BR-REC-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '500.00',
            'created_by' => $staff->id,
        ]);

        // Tamper a global customer wallet balance without ledger entries.
        $customer = Customer::factory()->create();
        DB::table('customers')->where('id', $customer->id)->update(['wallet_balance' => '999.00']);

        $exitBranch = Artisan::call('finance:reconcile', ['--branch_id' => $branch->id]);
        $this->assertSame(0, $exitBranch);
        $branchRun = FinanceReconcileRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(0, $branchRun->problem_count);
        $this->assertSame(1, $branchRun->checked);

        $exitAll = Artisan::call('finance:reconcile');
        $this->assertSame(1, $exitAll);
        $allRun = FinanceReconcileRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $allRun->problem_count);
    }

    public function test_f07_migrations_use_not_valid_and_precision_down_blocks_overflow(): void
    {
        $mig53 = file_get_contents(database_path('migrations/2026_11_12_000053_guard_wallet_adjustment_amount.php'));
        $this->assertIsString($mig53);
        $this->assertStringContainsString('NOT VALID', $mig53);
        $this->assertStringContainsString("lock_timeout = '3s'", $mig53);
        $this->assertStringContainsString('VALIDATE CONSTRAINT wallet_adjustments_amount_positive', $mig53);

        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::create([
            'name' => 'Large Expense Safe',
            'code' => 'LG-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);
        DB::table('expenses')->insert([
            'expense_number' => 'EXP-BIG-DOWN',
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'category' => 'أصول',
            'amount' => '15000000000.00',
            'expense_date' => '2026-11-12',
            'created_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_12_000070_align_treasury_shift_expense_precision.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
