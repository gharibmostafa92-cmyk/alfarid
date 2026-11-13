<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\Order;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Services\Pos\PosService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

/**
 * task6 backend lane: regression coverage for F2, F14, F7, F3-netting,
 * F4, F5, F11, F13, F9, F6, F16, F17, F18.
 */
class Task6BackendFixesTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function stockSum(string $column): float
    {
        return (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->sum($column);
    }

    public function test_f2_damaged_return_does_not_inflate_sellable_stock(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(200, 0, 2), $this->idem())
            ->assertCreated()->json('data');
        $onHandAfterSale = $this->stockSum('on_hand');
        $availableAfterSale = $this->stockSum('available');

        $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'damaged']],
        ], $this->idem())->assertOk();

        $this->assertSame($onHandAfterSale + 1, $this->stockSum('on_hand'));
        $this->assertSame(1.0, $this->stockSum('damaged'));
        $this->assertSame($availableAfterSale, $this->stockSum('available'));

        // SaleReturn history stays intact so previous-quantity accounting keeps working.
        $saleReturnQty = (float) StockMovement::query()
            ->where('reference_id', $sale['id'])
            ->where('type', StockMovementType::SaleReturn)
            ->sum('quantity');
        $this->assertSame(1.0, $saleReturnQty);

        // Buckets audit: exactly one Damaged movement.
        $this->assertSame(1, StockMovement::query()
            ->where('reference_id', $sale['id'])
            ->where('type', StockMovementType::Damage)
            ->where('affected_bucket', StockBucket::Damaged)
            ->count());
    }

    public function test_f14_cent_fractions_close_without_float_poisoning(): void
    {
        // 0.10 + 0.10 - 0.05 leaves a binary residue (~-2.7e-17) under (float)
        // math; the drawer math must still reconcile to the exact cent.
        $cashier = $this->makeCashier('f14@example.com', $this->branch->id, ['pos.access']);
        $drawer = Treasury::create([
            'name' => 'F14 Drawer', 'code' => 'DRW-F14-'.uniqid(),
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $this->openShift($cashier, $drawer, 0.10);
        $token = $this->tokenFor($cashier, ['pos.sales.refund']);

        $tenth = $this->makeVariant(0.10);
        $this->stockIn($this->branch->id, $tenth, 5);
        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $tenth->id, 'qty' => 1]],
            'cash_amount' => 0.10,
            'card_amount' => 0,
        ], $this->idem())->assertCreated();

        $shift = PosShift::query()->where('user_id', $cashier->id)->firstOrFail()->fresh();
        app(PosService::class)->recordExpense($shift, ['category' => 'ضيافة', 'amount' => 0.05], $cashier->id);

        $totals = app(PosService::class)->computeShiftTotals($shift->fresh());
        $this->assertSame('0.15', number_format($totals['expected_closing_cash'], 2, '.', ''));

        // Exact physical count must close cleanly: no phantom "invalid amount" failure.
        $this->as($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 0.15,
        ])->assertOk()->assertJsonPath('data.status', 'closed_pending_approval');
    }

    public function test_f7_record_expense_rejects_bad_amounts_and_missing_category(): void
    {
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail()->fresh();

        foreach ([
            'missing amount' => ['category' => 'ضيافة'],
            'zero amount' => ['category' => 'ضيافة', 'amount' => 0],
            'negative amount' => ['category' => 'ضيافة', 'amount' => -25],
            'non-numeric amount' => ['category' => 'ضيافة', 'amount' => 'abc'],
            'missing category' => ['amount' => 25],
            'blank category' => ['category' => '   ', 'amount' => 25],
        ] as $case => $payload) {
            try {
                app(PosService::class)->recordExpense($shift->fresh(), $payload, $this->cashier->id);
                $this->fail("recordExpense accepted invalid payload: {$case}");
            } catch (BusinessException $e) {
                $this->assertNotSame('', $e->getMessage(), $case);
            } catch (AuthorizationException $e) {
                $this->assertNotSame('', $e->getMessage(), $case);
            }
        }

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_f3_card_paid_swap_moves_no_cash(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(100.00);
        $this->stockIn($this->branch->id, $replacement, 15);

        // Original receipt paid entirely by card.
        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 10]],
            'cash_amount' => 0,
            'card_amount' => 1000,
        ], $this->idem())->assertCreated()->json('data');

        $drawerBefore = (float) $this->drawer->fresh()->current_balance;

        // Same-value swap with zero net tender: the return credit covers the replacement.
        $ex = $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 10]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 10]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertOk()->json('data');

        $this->assertSame($drawerBefore, (float) $this->drawer->fresh()->current_balance);

        $exchangeOrder = \App\Models\Order::findOrFail($ex['exchange_order_id']);
        $this->assertSame('0.00', (string) $exchangeOrder->cash_amount);
        $this->assertSame('0.00', (string) $exchangeOrder->card_amount);
        $this->assertSame('1000.00', (string) $exchangeOrder->exchange_credit);

        $returnOrder = \App\Models\Order::findOrFail($ex['return_order_id']);
        $this->assertSame('0.00', (string) $returnOrder->cash_amount);
        $this->assertSame('0.00', (string) $returnOrder->card_amount);
        $this->assertFalse((bool) $returnOrder->requires_refund);
    }

    public function test_f11_rejects_suspended_rejected_and_deleted_customers(): void
    {
        $token = $this->tokenFor($this->cashier);

        $suspended = Customer::factory()->create(['phone' => '01000000001', 'type' => CustomerType::Retail, 'status' => CustomerStatus::Suspended]);
        $rejected = Customer::factory()->create(['phone' => '01000000002', 'type' => CustomerType::Retail, 'status' => CustomerStatus::Rejected]);
        $deleted = Customer::factory()->create(['phone' => '01000000003', 'type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        $deleted->delete();
        $approved = Customer::factory()->create(['phone' => '01000000004', 'type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        $pending = Customer::factory()->create(['phone' => '01000000005', 'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Pending]);

        $payload = fn (?int $customerId) => array_merge(
            $this->salePayload(100, 0, 1),
            ['customer_id' => $customerId],
        );

        foreach ([$suspended->id, $rejected->id, $deleted->id] as $badId) {
            $this->as($token)->postJson('/api/v1/pos/sales', $payload($badId), $this->idem())
                ->assertStatus(422);
        }

        // Quote previews reject them too.
        foreach ([$suspended->id, $rejected->id, $deleted->id] as $badId) {
            $this->as($token)->postJson('/api/v1/pos/sales/quote', [
                'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
                'customer_id' => $badId,
            ])->assertStatus(422);
        }

        // Healthy and pending-review customers keep working.
        $this->as($token)->postJson('/api/v1/pos/sales', $payload($approved->id), $this->idem())
            ->assertCreated();
        $this->as($token)->postJson('/api/v1/pos/sales', $payload($pending->id), $this->idem())
            ->assertCreated();
    }

    public function test_f11_rejects_zero_grand_total_sales(): void
    {
        $this->cashier->givePermissionTo(
            \Spatie\Permission\Models\Permission::findOrCreate('pos.override_price', 'staff'),
            \Spatie\Permission\Models\Permission::findOrCreate('pos.override_price_below_cost', 'staff'),
        );
        $token = $this->tokenFor($this->cashier);

        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 0]],
            'override_price' => true,
            'override_reason' => 'هدية ترويجية',
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_f5_resume_held_deletes_atomically(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.held.view_all']);

        $held = $this->as($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'notes' => 'parked cart',
        ], $this->idem())->assertCreated()->json('data');

        $this->as($token)->postJson("/api/v1/pos/sales/held/{$held['id']}/resume", [])
            ->assertOk()
            ->assertJsonPath('data.hold_reference', $held['hold_reference']);

        // Active lease protects against concurrent duplicate resume (409) without losing the parked row.
        $this->assertDatabaseHas('pos_held_sales', [
            'id' => $held['id'],
            'resumed_by' => $this->cashier->id,
        ]);
        $this->as($token)->postJson("/api/v1/pos/sales/held/{$held['id']}/resume", [])
            ->assertStatus(409);

        // Checking out with held_sale_id deletes the held row atomically.
        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'cash_amount' => 100.00,
            'held_sale_id' => $held['id'],
        ], $this->idem())->assertCreated();

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held['id']]);
    }

    public function test_f4_store_rejects_discount_without_permission(): void
    {
        $token = $this->tokenFor($this->cashier);
        $payload = $this->salePayload(90, 0, 1, 10.0);

        $this->as($token)->postJson('/api/v1/pos/sales', $payload, $this->idem())
            ->assertForbidden();

        $privileged = $this->tokenFor($this->cashier, ['pos.discount']);
        $this->as($privileged)->postJson('/api/v1/pos/sales', $payload, $this->idem())
            ->assertCreated();
    }

    public function test_f3_cheaper_replacement_refunds_only_the_net_difference(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $cheaper = $this->makeVariant(80.00);
        $this->stockIn($this->branch->id, $cheaper, 5);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated()->json('data');
        $drawerBefore = (float) $this->drawer->fresh()->current_balance;

        // 100-cash item swapped for an 80-item with no extra tender: 20 cash walks out.
        $ex = $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $cheaper->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertOk()->json('data');

        $this->assertSame($drawerBefore - 20.0, (float) $this->drawer->fresh()->current_balance);

        $returnOrder = \App\Models\Order::findOrFail($ex['return_order_id']);
        $this->assertSame('20.00', (string) $returnOrder->cash_amount);
        $this->assertSame('0.00', (string) $returnOrder->card_amount);
    }

    public function test_f5_resume_then_sell_succeeds_with_consumed_hold(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.held.view_all']);

        $held = $this->as($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'notes' => 'parked cart',
        ], $this->idem())->assertCreated()->json('data');
        $this->assertDatabaseHas('pos_held_sales', ['id' => $held['id']]);

        // Resume acquires a soft lease while preserving the row until checkout consumes it.
        $this->as($token)->postJson("/api/v1/pos/sales/held/{$held['id']}/resume", [], $this->idem())
            ->assertOk()
            ->assertJsonPath('data.hold_reference', $held['hold_reference']);
        $this->assertDatabaseHas('pos_held_sales', [
            'id' => $held['id'],
            'resumed_by' => $this->cashier->id,
        ]);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', array_merge(
            $this->salePayload(100, 0, 1),
            ['held_sale_id' => $held['id']],
        ), $this->idem())->assertCreated();

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held['id']]);
        $this->assertSame($this->variant->id, $sale->json('data.items.0.product_variant_id'));
    }

    public function test_f5_fabricated_held_sale_id_proceeds_as_normal_sale(): void
    {
        $token = $this->tokenFor($this->cashier);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', array_merge(
            $this->salePayload(100, 0, 1),
            ['held_sale_id' => 999999],
        ), $this->idem())->assertCreated();

        $this->assertSame($this->variant->id, $sale->json('data.items.0.product_variant_id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_f5_wrong_branch_existing_hold_still_rejected(): void
    {
        $token = $this->tokenFor($this->cashier);

        $foreignHold = PosHeldSale::create([
            'hold_reference' => 'HOLD-FOREIGN-1',
            'branch_id' => $this->branchB->id,
            'user_id' => $this->cashierB->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'notes' => 'alex cart',
            'created_at' => now(),
        ]);

        $this->as($token)->postJson('/api/v1/pos/sales', array_merge(
            $this->salePayload(100, 0, 1),
            ['held_sale_id' => $foreignHold->id],
        ), $this->idem())->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
    }

    private function adminToken(User $manager): string
    {
        Auth::forgetGuards();

        return $manager->createToken('admin')->plainTextToken;
    }

    private function makeSupervisor(string $email, array $perms = ['pos_shifts.approve']): User
    {
        $manager = $this->makeCashier($email, $this->branch->id, ['pos.access']);
        foreach ($perms as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $manager;
    }

    public function test_f9_force_close_requires_counted_cash_and_records_forcer(): void
    {
        $manager = $this->makeSupervisor('forcer@example.com', ['pos_shifts.approve', 'pos_shifts.force_close']);
        $adminToken = $this->adminToken($manager);
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();

        // The counted cash is mandatory: no silent expected == actual.
        $this->withToken($adminToken)->postJson("/api/v1/admin/shifts/{$shift->id}/force-close", [
            'reason' => 'الموظف غادر دون إغلاق',
        ])->assertStatus(422);

        $this->withToken($adminToken)->postJson("/api/v1/admin/shifts/{$shift->id}/force-close", [
            'actual_closing_cash' => 50.00,
            'reason' => 'الموظف غادر دون إغلاق',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed_pending_approval')
            ->assertJsonPath('data.cash_difference', '50.00');

        $this->assertSame($manager->id, (int) $shift->fresh()->force_closed_by);

        // The forcer cannot approve their own force-close.
        $this->withToken($adminToken)->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertStatus(422);
        $this->assertSame('closed_pending_approval', $shift->fresh()->status->value);

        // A second supervisor settles (drawer 50 swept to the safe) and approves.
        $reviewer = $this->makeSupervisor('reviewer@example.com');
        $this->withToken($this->adminToken($reviewer))->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertNotNull($shift->fresh()->settled_at);
        $this->assertSame(0.0, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame(1050.0, (float) $this->safe->fresh()->current_balance);

        // Approving an approved shift is rejected: no double settlement.
        $this->withToken($this->adminToken($reviewer))->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertStatus(422);
        $this->assertSame(1050.0, (float) $this->safe->fresh()->current_balance);
    }

    public function test_f9_zero_zero_shift_settles_exactly_once(): void
    {
        $reviewer = $this->makeSupervisor('zero-reviewer@example.com');
        $reviewerToken = $this->adminToken($reviewer);

        $token = $this->tokenFor($this->cashier);
        $this->as($token)->postJson('/api/v1/pos/shifts/close', ['actual_closing_cash' => 0])->assertOk();
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();

        // NOTE: as() (not bare withToken()) — it resets the auth guards so the
        // approve request resolves the reviewer instead of the memoized cashier.
        $this->as($reviewerToken)->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertNotNull($shift->fresh()->settled_at);

        // A retry (or double-click) cannot settle twice.
        $this->withToken($this->adminToken($reviewer))->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertStatus(422);
        $this->assertSame('approved', $shift->fresh()->status->value);
        $this->assertSame(0.0, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame(1000.0, (float) $this->safe->fresh()->current_balance);
    }

    public function test_f13_branchless_cashier_is_rejected_before_branch_zero_queries(): void
    {
        $ghost = $this->makeCashier('ghost@example.com', null, ['pos.access']);
        $token = $this->tokenFor($ghost);

        $this->withToken($token)->getJson('/api/v1/pos/products/lookup?barcode=6221234567890')
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/pos/sales/held')
            ->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id,
            'opening_cash' => 0,
        ])->assertForbidden();
    }

    public function test_f13_branch_scoped_admin_filtering_other_branch_gets_403(): void
    {
        $manager = $this->makeSupervisor('scoped-admin@example.com', ['pos_shifts.view']);
        $adminToken = $this->adminToken($manager);

        $this->withToken($adminToken)->getJson("/api/v1/admin/shifts?branch_id={$this->branchB->id}")
            ->assertForbidden();

        // Their own branch remains listable.
        $this->withToken($adminToken)->getJson("/api/v1/admin/shifts?branch_id={$this->branch->id}")
            ->assertOk();
    }

    public function test_f6_catalog_version_stable_sensitive_and_cursor_pages_skip_compute(): void
    {
        $pos = app(PosService::class);
        $v1 = $pos->computeCatalogVersion($this->branch->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $v1);
        $this->assertSame($v1, $pos->computeCatalogVersion($this->branch->id));

        // A stock movement flips the version.
        $this->stockIn($this->branch->id, $this->variant, 1);
        $this->assertNotSame($v1, $pos->computeCatalogVersion($this->branch->id));

        // First page carries the version + ETag; cursor pages skip the compute.
        $token = $this->tokenFor($this->cashier);
        $first = $this->withToken($token)->getJson('/api/v1/pos/catalog')->assertOk();
        $this->assertNotEmpty($first->json('meta.catalog_version'));
        $this->assertNotEmpty($first->headers->get('ETag'));

        $this->makeVariant(55.00);
        $cursor = ProductVariant::query()->orderBy('id')->cursorPaginate(1)->nextCursor()?->encode();
        $this->assertNotNull($cursor);
        $page = $this->withToken($token)->getJson('/api/v1/pos/catalog?cursor='.urlencode($cursor))->assertOk();
        $this->assertSame('', $page->json('meta.catalog_version'));
        $this->assertFalse($page->headers->has('ETag'));
    }

    public function test_f16_return_lookup_exact_first_then_scoped_like_fallback(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $sale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated()->json('data');

        // Exact order-number hit.
        $this->as($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($sale['order_number']))
            ->assertOk()
            ->assertJsonPath('data.id', $sale['id']);

        // Custom branch prefix: exact candidates miss, the scoped LIKE fallback finds it.
        Order::whereKey($sale['id'])->update(['order_number' => 'BR9-000123']);
        $this->as($token)->getJson('/api/v1/pos/sales/lookup?number=123')
            ->assertOk()
            ->assertJsonPath('data.id', $sale['id']);

        // The same suffix parked in the other branch stays invisible here.
        $this->stockIn($this->branchB->id, $this->variant, 5);
        $tokenB = $this->openShift($this->cashierB, $this->drawerB, 0.0);
        // NOTE: as() resets the auth guards so this sale resolves cashier B;
        // bare withToken() would reuse the memoized cashier-A guard user.
        $other = $this->as($tokenB)->postJson('/api/v1/pos/sales', array_merge(
            $this->salePayload(100, 0, 1),
            ['items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]]],
        ), $this->idem())->assertCreated()->json('data');
        // Same trailing suffix, different full number: the scoped LIKE fallback
        // must still prefer the cashier's own branch (unique order numbers).
        Order::whereKey($other['id'])->update(['order_number' => 'BR8-000123']);

        $this->as($token)->getJson('/api/v1/pos/sales/lookup?number=123')
            ->assertOk()
            ->assertJsonPath('data.id', $sale['id']);

        // Past the return window the fallback no longer surfaces the receipt.
        Order::whereKey($sale['id'])->update(['created_at' => now()->subDays(60)]);
        $this->as($token)->getJson('/api/v1/pos/sales/lookup?number=123')->assertNotFound();
    }

    public function test_f17_precision_migration_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_12_000070_align_treasury_shift_expense_precision.php');
        // A re-run after a partial retry must be a cheap no-op, not a re-lock.
        $migration->up();

        foreach (['pos_shifts' => ['opening_cash', 'cash_difference'], 'expenses' => ['amount']] as $table => $columns) {
            foreach ($columns as $column) {
                $info = \Illuminate\Support\Facades\DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->first();
                $this->assertSame(14, (int) $info->numeric_precision);
                $this->assertSame(2, (int) $info->numeric_scale);
            }
        }
    }

    public function test_f18_held_sales_prune_index_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'pos_held_sales')
            ->where('indexname', 'pos_held_sales_created_at_index')
            ->exists());
    }

    public function test_f7_record_expense_rejects_foreign_user(): void
    {
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail()->fresh();
        $outsider = $this->makeCashier('outsider@example.com', $this->branchB->id, ['pos.access']);

        try {
            app(PosService::class)->recordExpense($shift, ['category' => 'ضيافة', 'amount' => 25], $outsider->id);
            $this->fail('recordExpense accepted an expense from another branch user.');
        } catch (AuthorizationException | BusinessException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertDatabaseCount('expenses', 0);
    }
}
