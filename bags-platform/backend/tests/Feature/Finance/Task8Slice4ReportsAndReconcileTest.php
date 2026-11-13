<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\FinanceReconcileRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\ReportService;
use App\Services\Finance\StatementBudget;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class Task8Slice4ReportsAndReconcileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->admin->assignRole('Super Admin');
    }

    public function test_f03_rma_return_prorates_tax_out_of_merchandise_profit_revenue(): void
    {
        $variant = ProductVariant::factory()->create(['cost_price' => '200.00']);
        $order = Order::factory()->create([
            'status' => 'delivered',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => '1000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '140.00',
            'shipping_cost' => '50.00',
            'cod_fee' => '0.00',
            'grand_total' => '1190.00',
            'created_at' => '2026-02-05 12:00:00',
        ]);
        $item = OrderItem::factory()->forVariant($variant)->create([
            'order_id' => $order->id,
            'allocated_branch_id' => $this->branch->id,
            'qty' => 2,
            'unit_price' => '500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '140.00',
            'line_total' => '1000.00',
        ]);
        $saleMov = new StockMovement([
            'reference_number' => 'T8-SALE-'.Str::uuid(),
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => 'sale',
            'affected_bucket' => 'on_hand',
            'quantity' => -2,
            'balance_before' => 10,
            'balance_after' => 8,
            'unit_cost' => '200.00',
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'created_by' => $this->admin->id,
        ]);
        $saleMov->created_at = '2026-02-05 12:00:00';
        $saleMov->save();

        // Full item RMA refund includes 1000.00 merchandise + 140.00 tax = 1140.00.
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'branch_id' => $this->branch->id,
            'status' => 'received',
            'received_at' => '2026-03-05 12:00:00',
            'total_refund_amount' => '1140.00',
        ]);
        ReturnRequestItem::factory()->create([
            'return_request_id' => $return->id,
            'order_item_id' => $item->id,
            'product_variant_id' => $variant->id,
            'requested_qty' => 2,
            'approved_qty' => 2,
            'received_good_qty' => 2,
            'unit_refund_price' => '570.00',
            'refund_amount' => '1140.00',
        ]);
        $retMov = new StockMovement([
            'reference_number' => 'T8-RET-'.Str::uuid(),
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => 'sale_return',
            'affected_bucket' => 'on_hand',
            'quantity' => 2,
            'balance_before' => 8,
            'balance_after' => 10,
            'unit_cost' => '200.00',
            'reference_type' => ReturnRequest::class,
            'reference_id' => $return->id,
            'created_by' => $this->admin->id,
        ]);
        $retMov->created_at = '2026-03-05 12:00:00';
        $retMov->save();

        $reports = app(ReportService::class);
        $febProfit = $reports->profitReport('2026-02-01', '2026-02-28', $this->branch->id);
        $marProfit = $reports->profitReport('2026-03-01', '2026-03-31', $this->branch->id);
        $marSales = $reports->salesReport('2026-03-01', '2026-03-31', branchId: $this->branch->id);

        $this->assertSame(1000.0, (float) $febProfit['net_sales_revenue']);
        // Merchandise reversal must exclude the 140.00 VAT refunded to the customer.
        $this->assertSame(-1000.0, (float) $marProfit['net_sales_revenue']);
        $this->assertSame(-600.0, (float) $marProfit['gross_profit']);
        $this->assertSame(-140.0, (float) $marSales['totals']['tax']);
        $this->assertSame(-1140.0, (float) $marSales['totals']['net_sales']);
    }

    public function test_f03_manual_return_ignores_updated_at_when_returned_at_is_null(): void
    {
        $order = Order::factory()->create([
            'status' => 'returned',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'grand_total' => '500.00',
            'created_at' => '2026-02-01 12:00:00',
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'returned_at' => null,
            'updated_at' => '2026-04-10 12:00:00',
        ]);

        $aprilProfit = app(ReportService::class)->profitReport('2026-04-01', '2026-04-30', $this->branch->id);
        $aprilSales = app(ReportService::class)->salesReport('2026-04-01', '2026-04-30', branchId: $this->branch->id);

        $this->assertSame(0.0, (float) $aprilProfit['net_sales_revenue']);
        $this->assertSame(0.0, (float) $aprilSales['totals']['net_sales']);
    }

    public function test_f03_profit_report_does_not_use_unbounded_orders_left_join_or_case_when(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReportService::class)->profitReport('2026-02-01', '2026-02-28', $this->branch->id);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $joined = implode("\n", $queries);
        $this->assertStringNotContainsString('CASE WHEN entries.sale_count = 1', $joined);
    }

    public function test_f05_reconcile_splits_read_only_snapshot_from_run_logging_and_pruning(): void
    {
        // Insert one stale run (>90 days) and one recent run to verify audit retention.
        $stale = FinanceReconcileRun::create([
            'branch_id' => $this->branch->id,
            'checked' => 1,
            'problem_count' => 0,
            'samples' => [],
        ]);
        DB::table('finance_reconcile_runs')->where('id', $stale->id)->update([
            'created_at' => now()->subDays(120),
        ]);
        $recent = FinanceReconcileRun::create([
            'branch_id' => $this->branch->id,
            'checked' => 1,
            'problem_count' => 0,
            'samples' => [],
        ]);
        DB::table('finance_reconcile_runs')->where('id', $recent->id)->update([
            'created_at' => now()->subDays(20),
        ]);

        // StatementBudget readOnly flag works cleanly.
        $val = StatementBudget::run(5000, fn () => DB::selectOne('SELECT 1 AS ok')->ok, readOnly: true);
        $this->assertSame(1, (int) $val);

        $exit = Artisan::call('finance:reconcile', ['--branch_id' => $this->branch->id]);
        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('finance_reconcile_runs', ['id' => $stale->id]);
        $this->assertDatabaseHas('finance_reconcile_runs', ['id' => $recent->id]);
        $this->assertSame(2, FinanceReconcileRun::query()->count());
    }

    public function test_f10_inventory_report_fails_closed_with_503_on_lock_timeout_and_normalizes_dead_days(): void
    {
        Cache::flush();

        // Non-preset dead_days snap to a discrete canonical bucket so cache keys stay bounded.
        $variant = ProductVariant::factory()->create(['cost_price' => '25.00']);
        StockLevel::forceCreate([
            'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 4,
        ]);

        Auth::forgetGuards();
        $res = $this->withToken($this->admin->createToken('r1')->plainTextToken)
            ->getJson('/api/v1/admin/reports/inventory?dead_days=17')
            ->assertOk();
        $this->assertSame(14, $res->json('data.dead_stock_days'));

        // Simulate lock contention after cache miss: must fail closed with HTTP 503 instead of unguarded scan.
        Cache::flush();
        $fakeLock = Mockery::mock(Lock::class);
        $fakeLock->shouldReceive('block')
            ->once()
            ->andThrow(new LockTimeoutException('Lock timed out'));

        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('lock')->once()->andReturnUsing(function (string $key, int $seconds) use ($fakeLock) {
            $this->assertGreaterThan(15, $seconds, 'Lock TTL must strictly exceed 15s HTTP statement budget.');

            return $fakeLock;
        });

        try {
            app(ReportService::class)->inventoryReport(90, $this->branch->id, true, 15000);
            $this->fail('Expected 503 BusinessException on LockTimeoutException.');
        } catch (BusinessException $e) {
            $this->assertSame(503, $e->status);
        }
    }

    public function test_f11_shifts_report_uses_decimal_comparison_and_precision(): void
    {
        $treasury = Treasury::create([
            'name' => 'T8 Drawer',
            'code' => 'DRW-'.Str::uuid(),
            'branch_id' => $this->branch->id,
            'type' => 'pos_drawer',
            'current_balance' => '0.00',
            'is_active' => true,
        ]);

        PosShift::create([
            'shift_number' => 'SH-PREC-1',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
            'treasury_id' => $treasury->id,
            'status' => 'approved',
            'opened_at' => now(),
            'closed_at' => now(),
            'opening_cash' => '0.00',
            'cash_sales_total' => '1000000.15',
            'card_sales_total' => '0.05',
            'expected_closing_cash' => '1000000.15',
            'actual_closing_cash' => '1000000.14',
            'cash_difference' => '-0.01',
        ]);

        $day = now((string) config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        $report = app(ReportService::class)->shiftsReport($day, $day, false, $this->branch->id);

        $this->assertSame(1000000.15, $report['rows'][0]['cash_sales_total']);
        $this->assertSame(-0.01, $report['rows'][0]['cash_difference']);
        $this->assertTrue($report['rows'][0]['has_difference']);
    }
}
