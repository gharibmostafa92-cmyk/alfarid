<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Treasury;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('admin')->plainTextToken;
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->token);
    }

    private function order(array $attrs): Order
    {
        return Order::factory()->create(array_merge([
            'status' => 'delivered',
            'subtotal' => 100,
            'grand_total' => 100,
        ], $attrs));
    }

    public function test_dashboard_kpis_by_channel_and_counts(): void
    {
        $branch = Branch::factory()->create();
        $this->order(['channel' => 'store', 'grand_total' => 1000, 'allocated_branch_id' => $branch->id]);
        $this->order(['channel' => 'pos', 'grand_total' => 500]);
        $this->order(['channel' => 'wholesale', 'status' => 'confirmed', 'grand_total' => 300]);
        $this->order(['channel' => 'store', 'status' => 'cancelled', 'grand_total' => 999]);

        Customer::factory()->create(['type' => 'wholesale', 'status' => 'pending']);

        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);
        StockLevel::forceCreate(['branch_id' => $branch->id, 'product_variant_id' => $variant->id, 'on_hand' => 3]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/dashboard')->assertOk();
        $response->assertJsonPath('data.today.sales_total', 1800)
            ->assertJsonPath('data.today.sales_by_channel.store', 1000)
            ->assertJsonPath('data.today.sales_by_channel.pos', 500)
            ->assertJsonPath('data.today.sales_by_channel.wholesale', 300)
            ->assertJsonPath('data.today.new_orders_count', 4)
            ->assertJsonPath('data.month.sales_total', 1800)
            ->assertJsonPath('data.pending_wholesale_applications', 1);
        $this->assertSame(1, $response->json('data.low_stock_count'));
        $this->assertSame($branch->id, $response->json('data.top_branches.0.branch_id'));
    }

    public function test_sales_report_grouped_by_channel_with_totals(): void
    {
        $this->order(['channel' => 'store', 'subtotal' => 900, 'discount_amount' => 100, 'tax_amount' => 50, 'shipping_cost' => 50, 'grand_total' => 900]);
        $this->order(['channel' => 'pos', 'subtotal' => 200, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_cost' => 0, 'grand_total' => 200]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/reports/sales?group_by=channel')->assertOk();
        $response->assertJsonPath('data.totals.orders_count', 2)
            ->assertJsonPath('data.totals.gross_sales', 1100)
            ->assertJsonPath('data.totals.discounts', 100)
            ->assertJsonPath('data.totals.tax', 50)
            ->assertJsonPath('data.totals.net_sales', 1100);
        $keys = collect($response->json('data.rows'))->pluck('group_key')->all();
        $this->assertEqualsCanonicalizing(['store', 'pos'], $keys);
    }

    public function test_inventory_valuation_and_dead_stock(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $dead = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 50]);
        StockLevel::forceCreate(['branch_id' => $branch->id, 'product_variant_id' => $dead->id, 'on_hand' => 10]);

        $moving = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 20]);
        StockLevel::forceCreate(['branch_id' => $branch->id, 'product_variant_id' => $moving->id, 'on_hand' => 5]);
        StockMovement::create([
            'reference_number' => 'MOV-TEST-1',
            'product_variant_id' => $moving->id,
            'branch_id' => $branch->id,
            'type' => 'sale',
            'affected_bucket' => 'on_hand',
            'quantity' => -1,
            'balance_before' => 6,
            'balance_after' => 5,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk();
        $response->assertJsonPath('data.total_value', 600);
        $this->assertSame(600.0, (float) collect($response->json('data.valuation'))->firstWhere('branch_id', $branch->id)['total_value']);
        $deadIds = collect($response->json('data.dead_stock'))->pluck('product_variant_id')->all();
        $this->assertContains($dead->id, $deadIds);
        $this->assertNotContains($moving->id, $deadIds);
    }

    public function test_profit_report_uses_sale_movement_costs_and_expenses(): void
    {
        $this->order(['grand_total' => 1000]);
        $variant = ProductVariant::factory()->create(['cost_price' => 999]);
        StockMovement::create([
            'reference_number' => 'MOV-TEST-2',
            'product_variant_id' => $variant->id,
            'branch_id' => Branch::factory()->create()->id,
            'type' => 'sale',
            'affected_bucket' => 'on_hand',
            'quantity' => -2,
            'balance_before' => 10,
            'balance_after' => 8,
            'unit_cost' => 100,
            'created_by' => $this->admin->id,
        ]);
        DB::table('expenses')->insert([
            'expense_number' => 'EXP-TEST-1',
            'branch_id' => Branch::factory()->create()->id,
            'treasury_id' => Treasury::create(['name' => 'خزينة', 'code' => 'TR-'.uniqid(), 'type' => 'main_safe', 'current_balance' => 0, 'is_active' => true])->id,
            'category' => 'إيجار',
            'amount' => 150,
            'expense_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // COGS من unit_cost المسجل في الحركة (100×2) لا من تكلفة المتغير الحالية
        $this->asAdmin()->getJson('/api/v1/admin/reports/profit')->assertOk()
            ->assertJsonPath('data.net_sales_revenue', 1000)
            ->assertJsonPath('data.cogs', 200)
            ->assertJsonPath('data.gross_profit', 800)
            ->assertJsonPath('data.total_expenses', 150)
            ->assertJsonPath('data.net_profit', 650);
    }

    public function test_suppliers_report_aging_buckets(): void
    {
        $supplier = Supplier::factory()->create(['current_balance' => 600]);
        SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => 'posted',
            'subtotal' => 860, 'tax_amount' => 140,
            'total_amount' => 1000,
            'paid_amount' => 400,
            'due_date' => now()->subDays(45)->toDateString(),
        ]);
        SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'subtotal' => 777, 'tax_amount' => 0,
            'total_amount' => 777,
            'paid_amount' => 0,
        ]);

        // Procurement HTTP endpoints are retired; keep testing the retained report math directly.
        $this->asAdmin()->getJson('/api/v1/admin/reports/suppliers')->assertGone();
        $row = collect(app(ReportService::class)->suppliersReport())
            ->firstWhere('supplier_id', $supplier->id);
        $this->assertSame(600.0, (float) $row['current_balance']);
        $this->assertSame(1000.0, (float) $row['total_purchases']);
        $this->assertSame(600.0, (float) $row['aging']['overdue_31_60']);
        $this->assertSame(0.0, (float) $row['aging']['not_due']);
    }

    public function test_shifts_report_flags_differences(): void
    {
        $treasury = Treasury::create(['name' => 'خزينة', 'code' => 'TR-'.uniqid(), 'type' => 'pos_drawer', 'current_balance' => 0, 'is_active' => true]);
        $mk = fn (array $attrs) => PosShift::create(array_merge([
            'shift_number' => 'SH-'.uniqid(),
            'user_id' => $this->admin->id,
            'branch_id' => Branch::factory()->create()->id,
            'treasury_id' => $treasury->id,
            'status' => 'approved',
            'opened_at' => now(),
            'closed_at' => now(),
            'opening_cash' => 0,
            'cash_sales_total' => 700,
            'card_sales_total' => 300,
            'expected_closing_cash' => 700,
            'actual_closing_cash' => 700,
            'cash_difference' => 0,
        ], $attrs));
        $mk([]);
        $mk(['actual_closing_cash' => 650, 'cash_difference' => -50]);

        $all = $this->asAdmin()->getJson('/api/v1/admin/reports/shifts')->assertOk();
        $all->assertJsonPath('data.totals.shifts_count', 2)
            ->assertJsonPath('data.totals.shifts_with_difference', 1);

        $only = $this->asAdmin()->getJson('/api/v1/admin/reports/shifts?only_differences=1')->assertOk();
        $this->assertCount(1, $only->json('data.rows'));
        $this->assertTrue($only->json('data.rows.0.has_difference'));
        $this->assertSame(-50.0, (float) $only->json('data.rows.0.cash_difference'));
    }

    public function test_reports_forbidden_without_reports_view_permission(): void
    {
        $plain = User::factory()->create();
        Auth::forgetGuards();
        $this->withToken($plain->createToken('plain')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($plain->createToken('plain2')->plainTextToken)
            ->getJson('/api/v1/admin/reports/sales')->assertForbidden();
    }
}
