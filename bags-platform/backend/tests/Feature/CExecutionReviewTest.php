<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Services\CostAccess;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PosService;
use App\Services\ProcurementService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StocktakeService;
use App\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CExecutionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Treasury $safe;

    private Treasury $drawer;

    private PosShift $shift;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $base = ['branch_id' => $this->branch->id, 'is_active' => true];
        $this->safe = Treasury::create([...$base, 'name' => 'Safe', 'code' => 'SAFE', 'type' => 'main_safe', 'current_balance' => 1000]);
        $this->drawer = Treasury::create([...$base, 'name' => 'Drawer', 'code' => 'DRAWER', 'type' => 'pos_drawer', 'current_balance' => 0]);
        $this->shift = app(PosService::class)->openShift($this->cashier, $this->branch->id, $this->drawer->id, 200);
        $this->variant = ProductVariant::factory()->create(['retail_price' => 10, 'cost_price' => 4]);
        $this->variant->product()->update(['status' => 'published', 'published_at' => now()]);
    }

    private function stock(int $qty = 20, ?int $location = null): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'location_id' => $location, 'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand, 'quantity' => $qty, 'created_by' => $this->cashier->id,
        ]);
    }

    private function payload(int $qty = 1, array $extra = []): array
    {
        return ['items' => [['product_variant_id' => $this->variant->id, 'qty' => $qty, ...$extra]]];
    }

    private function permit(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
    }

    private function posApi(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->cashier->createToken('pos', ['pos'])->plainTextToken);
    }

    public function test_pos_sales_use_sequence_numbers_without_locking_a_gapless_settings_counter(): void
    {
        $this->stock();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $sale = app(PosService::class)->createSale($this->shift, $this->payload());
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotEmpty($sale->order_number);
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query['query'], 'document_next_number') && in_array('order', $query['bindings'], true)));
        $this->assertFalse($queries->contains(fn ($query) => str_contains($query['query'], '"settings"') && str_contains(strtolower($query['query']), 'for update')));
    }

    public function test_opening_float_and_shortage_conserve_cash_and_approval_is_once_only(): void
    {
        $this->assertSame('800.00', $this->safe->fresh()->current_balance);
        $this->assertSame('200.00', $this->drawer->fresh()->current_balance);
        $closed = app(PosService::class)->closeShift($this->shift, 180, null);
        app(PosService::class)->approveShift($closed, $this->cashier);
        $this->assertSame('980.00', $this->safe->fresh()->current_balance);
        $this->assertSame('0.00', $this->drawer->fresh()->current_balance);
        $this->assertDatabaseHas('treasury_transactions', ['type' => 'cash_shortage', 'amount' => '-20.00']);
        try {
            app(PosService::class)->approveShift($closed, $this->cashier);
            $this->fail('A stale closed model must not settle twice.');
        } catch (BusinessException) {
            $this->assertSame('980.00', $this->safe->fresh()->current_balance);
        }
    }

    public function test_overage_is_recorded_and_stale_open_shift_cannot_sell(): void
    {
        $this->stock();
        $closed = app(PosService::class)->closeShift($this->shift, 210, null);
        app(PosService::class)->approveShift($closed, $this->cashier);
        $this->assertSame('1010.00', $this->safe->fresh()->current_balance);
        $this->assertDatabaseHas('treasury_transactions', ['type' => 'cash_overage', 'amount' => '10.00']);
        $this->expectException(BusinessException::class);
        app(PosService::class)->createSale($this->shift, $this->payload());
    }

    public function test_partial_returns_round_cumulatively_and_do_not_refund_card_in_cash(): void
    {
        $this->stock();
        $this->permit('pos.discount');
        $sale = app(PosService::class)->createSale($this->shift, [
            ...$this->payload(3, ['discount_amount' => 1]), 'cash_amount' => 0, 'card_amount' => 29,
        ]);
        $refunds = [];
        for ($i = 0; $i < 3; $i++) {
            $result = app(PosService::class)->processReturn($this->shift, $sale, [['product_variant_id' => $this->variant->id, 'qty' => 1]]);
            $return = Order::findOrFail($result['return_order_id']);
            $refunds[] = $result['refund_amount'];
            $this->assertSame('0.00', $return->cash_amount);
            $this->assertSame('refund_pending', $return->payment_status);
        }
        $this->assertSame([9.67, 9.66, 9.67], $refunds);
        $this->assertSame('3.0000', $sale->items()->first()->returned_qty);
        $this->assertSame('200.00', $this->drawer->fresh()->current_balance);
        $this->expectException(BusinessException::class);
        app(PosService::class)->processReturn($this->shift, $sale, [['product_variant_id' => $this->variant->id, 'qty' => 1]]);
    }

    public function test_pos_sells_located_stock_and_receives_damaged_returns_at_receiving_location(): void
    {
        $shelf = Location::factory()->create(['branch_id' => $this->branch->id]);
        $receiving = Location::factory()->create(['branch_id' => $this->branch->id, 'type' => 'receiving']);
        $this->stock(5, $shelf->id);
        $sale = app(PosService::class)->createSale($this->shift, $this->payload(2));
        app(PosService::class)->processReturn($this->shift, $sale, [['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'damaged']]);
        $level = StockLevel::where('location_id', $receiving->id)->firstOrFail();
        $this->assertSame('1.0000', $level->on_hand);
        $this->assertSame('1.0000', $level->damaged);
        $this->assertSame(0.0, $level->available);
        $this->assertSame('3.0000', StockLevel::where('location_id', $shelf->id)->first()->on_hand);
    }

    public function test_sale_retries_reuse_one_order_and_changed_payload_conflicts(): void
    {
        $this->stock();
        $api = $this->posApi();
        $first = $api->postJson('/api/v1/pos/sales', $this->payload(), ['Idempotency-Key' => 'sale-retry'])->assertCreated();
        $api->postJson('/api/v1/pos/sales', $this->payload(), ['Idempotency-Key' => 'sale-retry'])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $api->postJson('/api/v1/pos/sales', $this->payload(2), ['Idempotency-Key' => 'sale-retry'])->assertConflict();
        $this->assertSame(1, Order::where('channel', 'pos')->count());
        $this->assertSame('210.00', $this->drawer->fresh()->current_balance);
    }

    public function test_sale_requires_retry_key_and_manual_discount_permission(): void
    {
        $this->stock();
        $this->posApi()->postJson('/api/v1/pos/sales', $this->payload())->assertUnprocessable();
        $this->expectException(AuthorizationException::class);
        app(PosService::class)->createSale($this->shift, $this->payload(1, ['discount_amount' => 1]));
    }

    public function test_profit_cost_is_frozen_and_returns_reduce_both_sales_and_cost(): void
    {
        $this->stock();
        $sale = app(PosService::class)->createSale($this->shift, $this->payload(2));
        $this->variant->update(['cost_price' => 99]);
        app(PosService::class)->processReturn($this->shift, $sale, [['product_variant_id' => $this->variant->id, 'qty' => 1]]);
        $day = now('Africa/Cairo')->toDateString();
        $report = app(ReportService::class)->profitReport($day, $day, $this->branch->id);
        $this->assertSame(10.0, $report['net_sales_revenue']);
        $this->assertSame(4.0, $report['cogs']);
        $this->assertSame(10.0, app(ReportService::class)->salesReport($day, $day)['totals']['net_sales']);
    }

    public function test_reports_use_local_day_and_reject_unbounded_ranges(): void
    {
        Order::factory()->create(['allocated_branch_id' => $this->branch->id, 'status' => 'delivered', 'grand_total' => 7, 'created_at' => '2026-10-23 22:30:00']);
        $this->assertSame(7.0, app(ReportService::class)->salesReport('2026-10-24', '2026-10-24', branchId: $this->branch->id)['totals']['net_sales']);
        $this->expectException(ValidationException::class);
        app(ReportService::class)->profitReport('2000-01-01', '2026-10-24');
    }

    public function test_default_report_dates_follow_the_business_day_near_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC'));
        $this->permit('reports.view');
        Order::factory()->create(['allocated_branch_id' => $this->branch->id, 'status' => 'delivered', 'grand_total' => 7]);
        Auth::forgetGuards();
        $this->withToken($this->cashier->createToken('admin')->plainTextToken)
            ->getJson('/api/v1/admin/reports/sales')->assertOk()->assertJsonPath('data.totals.net_sales', 7);
        $this->travelBack();
    }

    public function test_transfer_requires_another_approver_and_stale_approval_cannot_repeat(): void
    {
        $service = app(TreasuryService::class);
        $transfer = $service->createTransfer(['from_treasury_id' => $this->safe->id, 'to_treasury_id' => $this->drawer->id, 'amount' => 10], $this->cashier->id);
        try {
            $service->approveTransfer($transfer, $this->cashier->id);
            $this->fail('Self approval must fail.');
        } catch (BusinessException) {
            $this->assertSame('800.00', $this->safe->fresh()->current_balance);
        }
        $approver = User::factory()->create(['branch_id' => $this->branch->id]);
        $service->approveTransfer($transfer, $approver->id);
        try {
            $service->rejectTransfer($transfer, $approver->id);
            $this->fail('A stale transfer cannot overwrite approved state.');
        } catch (BusinessException) {
            $this->assertSame('790.00', $this->safe->fresh()->current_balance);
            $this->assertSame('approved', $transfer->fresh()->status->value);
        }
    }

    public function test_branch_scopes_hide_other_treasuries_and_sales(): void
    {
        $this->permit('treasuries.view', 'reports.view');
        $otherBranch = Branch::factory()->create();
        $otherSafe = Treasury::create(['branch_id' => $otherBranch->id, 'name' => 'Other', 'code' => 'OTHER', 'type' => 'main_safe', 'is_active' => true]);
        Order::factory()->create(['allocated_branch_id' => $otherBranch->id, 'status' => 'delivered', 'grand_total' => 99]);
        Auth::forgetGuards();
        $this->withToken($this->cashier->createToken('admin')->plainTextToken)
            ->getJson('/api/v1/admin/treasuries/'.$otherSafe->id)->assertForbidden();
        $this->getJson('/api/v1/admin/reports/profit')->assertOk()->assertJsonPath('data.net_sales_revenue', 0);
        $this->getJson('/api/v1/admin/treasuries')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_incremental_count_keeps_first_snapshot_after_intervening_movement(): void
    {
        $this->stock();
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id], $this->cashier->id);
        app(StocktakeService::class)->incrementItemCount($stocktake, $this->variant->id, 1, $this->cashier->id);
        $before = $stocktake->items()->firstOrFail();
        $this->stock(2);
        app(StocktakeService::class)->incrementItemCount($stocktake, $this->variant->id, 1, $this->cashier->id);
        $after = $before->fresh();
        $this->assertSame($before->snapshot_movement_id, $after->snapshot_movement_id);
        $this->assertSame($before->system_qty, $after->system_qty);
        $this->assertSame('2.0000', $after->counted_qty);
    }

    public function test_ipv6_rotating_addresses_share_daily_checkout_quota(): void
    {
        $limiter = RateLimiter::limiter('checkout');
        $keys = [];
        foreach (['2001:db8:1:2::1', '2001:db8:1:2::ffff'] as $ip) {
            $request = Request::create('/api/v1/orders', 'POST', ['recipient_phone' => '01012345678'], server: ['REMOTE_ADDR' => $ip]);
            $limits = $limiter($request);
            $keys[] = $limits[1]->key;
        }
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_incremental_reconciliation_keeps_corrupt_keys_until_fixed(): void
    {
        $this->stock();
        $this->artisan('inventory:reconcile --incremental')->assertSuccessful();
        $this->assertSame(0, DB::table('inventory_reconcile_work')->count());
        $level = StockLevel::where('product_variant_id', $this->variant->id)->firstOrFail();
        DB::table('stock_levels')->where('id', $level->id)->update(['on_hand' => 21]);
        $this->artisan('inventory:reconcile --incremental')->assertFailed();
        $this->assertSame(1, DB::table('inventory_reconcile_work')->count());
        DB::table('stock_levels')->where('id', $level->id)->update(['on_hand' => 20]);
        $this->artisan('inventory:reconcile --incremental')->assertSuccessful();
        $this->assertSame(0, DB::table('inventory_reconcile_work')->count());
        $this->stock(2);
        $this->artisan('inventory:reconcile --incremental --limit=1')->assertSuccessful();
        $this->assertSame(0, DB::table('inventory_reconcile_work')->count());
    }

    public function test_low_stock_join_and_branch_disabling_with_transit(): void
    {
        $this->permit('inventory.view', 'branches.update');
        $this->stock(1);
        $this->variant->update(['reorder_level' => 5]);
        Auth::forgetGuards();
        $this->withToken($this->cashier->createToken('admin')->plainTextToken)
            ->getJson('/api/v1/admin/inventory?low_stock=1')->assertOk();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::TransferInTransit, 'affected_bucket' => StockBucket::InTransit,
            'quantity' => 1, 'created_by' => $this->cashier->id,
        ]);
        $this->permit('inventory.all_branches');
        Auth::forgetGuards();
        $this->patchJson('/api/v1/admin/branches/'.$this->branch->id, ['is_active' => false])->assertUnprocessable();
        $this->assertTrue($this->branch->fresh()->is_active);
    }

    public function test_cost_visibility_uses_both_existing_permission_names_consistently(): void
    {
        $this->assertFalse(CostAccess::canView($this->cashier));
        $this->permit('inventory.view_cost');
        $this->assertTrue(CostAccess::canView($this->cashier));
        $this->cashier->revokePermissionTo('inventory.view_cost');
        $this->permit('products.view_cost');
        $this->assertTrue(CostAccess::canView($this->cashier));
        $this->assertFalse(CostAccess::canView(null));
    }

    public function test_pos_returns_cannot_be_repeated_through_rma_or_manual_order_status(): void
    {
        $this->stock();
        $sale = app(PosService::class)->createSale($this->shift, $this->payload());
        try {
            app(ReturnService::class)->createReturnRequest($sale, ['reason' => 'Return', 'items' => [['order_item_id' => $sale->items->first()->id, 'requested_qty' => 1]]]);
            $this->fail('POS must use a single return path.');
        } catch (BusinessException) {
            $this->assertDatabaseCount('return_requests', 0);
        }
        $this->expectException(BusinessException::class);
        app(OrderService::class)->changeStatus($sale, OrderStatus::Returned, $this->cashier);
    }

    public function test_stock_balances_cannot_be_mass_assigned(): void
    {
        $level = new StockLevel;
        foreach (['on_hand', 'reserved', 'damaged', 'in_transit'] as $bucket) {
            $this->assertFalse($level->isFillable($bucket));
        }
    }

    public function test_retained_procurement_cost_math_includes_owned_in_transit_stock(): void
    {
        $this->stock(10);
        foreach ([[StockMovementType::TransferOut, StockBucket::OnHand, -6], [StockMovementType::TransferInTransit, StockBucket::InTransit, 6]] as [$type, $bucket, $qty]) {
            app(InventoryService::class)->recordMovement([
                'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
                'type' => $type, 'affected_bucket' => $bucket, 'quantity' => $qty, 'created_by' => $this->cashier->id,
            ]);
        }
        $po = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->cashier->id, 'status' => 'sent_to_supplier', 'subtotal' => 80, 'discount_amount' => 0]);
        $item = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po->id, 'product_variant_id' => $this->variant->id, 'ordered_qty' => 10, 'unit_cost' => 8]);
        app(ProcurementService::class)->receivePurchaseOrder($po, [['purchase_order_item_id' => $item->id, 'qty' => 10]], performedBy: $this->cashier->id, idempotencyKey: 'retained-procurement-cost');
        $this->assertSame('6.0000', $this->variant->fresh()->cost_price);
        $this->assertSame('8.0000', $this->variant->fresh()->last_cost_price);
        $this->getJson('/api/v1/admin/purchase-orders')->assertGone();
    }

    public function test_unlinked_legacy_refunds_require_reconciliation_before_more_cash_is_paid(): void
    {
        $this->stock();
        $sale = app(PosService::class)->createSale($this->shift, $this->payload(3));
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::SaleReturn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 1, 'reference_type' => Order::class, 'reference_id' => $sale->id, 'created_by' => $this->cashier->id,
        ]);
        try {
            app(PosService::class)->processReturn($this->shift, $sale, [['product_variant_id' => $this->variant->id, 'qty' => 1]]);
            $this->fail('Unknown historical cash/card refunds must not be guessed.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('230.00', $this->drawer->fresh()->current_balance);
            $this->assertSame(0, Order::whereNotNull('return_of_order_id')->count());
        }
    }
}
