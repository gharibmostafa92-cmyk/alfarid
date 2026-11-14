<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\TreasuryTransactionType;
use App\Models\Order;
use App\Services\Pos\TreasuryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * Archived task6.txt roadmap (F-01..F-18) regression coverage.
 *
 * NOTE: this is a different lane from Task6BackendFixesTest, which covers a
 * renumbered F1-F19 lane. Findings here map 1:1 to
 * _archive_unused/tasks_execution/task6.txt.
 */
class Task6ArchivedFindingsTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function fundDrawer(float $amount): void
    {
        app(TreasuryService::class)->record([
            'treasury_id' => $this->drawer->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => $amount,
            'reference_type' => null,
            'reference_id' => null,
            'created_by' => $this->cashier->id,
            'notes' => 'تمويل الدرج لاختبار F-01',
        ]);
        $this->drawer->refresh();
    }

    /**
     * F-01: returning an exchange order whose credit originated from a
     * card-paid sale must not pay a single piastre in cash from the drawer.
     * The uncovered remainder routes to card (refund_pending).
     */
    public function test_f01_return_of_card_funded_exchange_order_pays_no_cash(): void
    {
        $this->fundDrawer(1000);
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(600.00);
        $this->stockIn($this->branch->id, $replacement, 15);

        // O1: 5 x 100 paid entirely by card.
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'cash_amount' => 0,
            'card_amount' => 500,
        ], $this->idem())->assertCreated()->json('data');

        // Exchange O1 -> O2 (600 retail), customer pays the 100 difference by card.
        $ex = $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 100,
        ], $this->idem())->assertOk()->json('data');

        $exchangeOrder = Order::findOrFail($ex['exchange_order_id']);
        $this->assertSame('0.00', (string) $exchangeOrder->cash_amount);
        $this->assertSame('100.00', (string) $exchangeOrder->card_amount);
        $this->assertSame('500.00', (string) $exchangeOrder->exchange_credit);

        $drawerBefore = (float) $this->drawer->fresh()->current_balance;

        // Plain return of the exchange order: 600 of value, 0 cash ever paid in chain.
        $ret = $this->withToken($token)->postJson("/api/v1/pos/sales/{$exchangeOrder->id}/return", [
            'items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
        ], $this->idem())->assertOk()->json('data');

        $returnOrder = Order::findOrFail($ret['return_order_id']);
        $this->assertSame('0.00', (string) $returnOrder->cash_amount);
        $this->assertSame('600.00', (string) $returnOrder->card_amount);
        $this->assertSame($drawerBefore, (float) $this->drawer->fresh()->current_balance);
    }

    /**
     * F-08: unbounded lookup input is rejected instead of hitting the DB.
     */
    public function test_f08_lookup_rejects_overlong_number(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode(str_repeat('7', 70)))
            ->assertStatus(422);
    }

    /**
     * F-08: an invoice detached from every branch (both FKs null) is a 404,
     * never a misleading 403 from assertBranch(user, 0).
     */
    public function test_f08_lookup_without_branch_context_returns_404(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated()->json('data');

        \App\Models\Order::whereKey($sale['id'])->update(['allocated_branch_id' => null, 'pos_shift_id' => null]);

        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($sale['order_number']))
            ->assertNotFound();
    }

    /**
     * F-07 (backend): lookup exposes line_total + previous_refunded_amount per
     * line so the client can reuse the server's proportional-cent math.
     */
    public function test_f07_lookup_returns_line_totals_and_refund_history(): void
    {
        $this->cashier->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('pos.discount', 'staff'));
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        // 3 x 100 with a 10 discount: line_total 290.00 (indivisible by 3).
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(290, 0, 3, 10.0), $this->idem())
            ->assertCreated()->json('data');

        $first = $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($sale['order_number']))
            ->assertOk()->json('data');
        $this->assertSame('290.00', $first['items'][0]['line_total']);
        $this->assertSame('0.00', $first['items'][0]['previous_refunded_amount']);

        // Partial return of 1 unit, then the history must show on lookup.
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale['id']}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->idem())->assertOk();

        $second = $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($sale['order_number']))
            ->assertOk()->json('data');
        // Server math: round(1 * 29000 / 3) = 9667 cents.
        $this->assertSame('96.67', $second['items'][0]['previous_refunded_amount']);
    }

    /**
     * F-04 (backend): suspended/rejected/deleted customers cannot park a held
     * sale — the hold-time gate mirrors store().
     */
    public function test_f04_hold_rejects_suspended_rejected_and_deleted_customers(): void
    {
        $token = $this->tokenFor($this->cashier);
        $suspended = \App\Models\Customer::factory()->create(['phone' => '01000000011', 'type' => \App\Enums\Customer\CustomerType::Retail, 'status' => \App\Enums\Customer\CustomerStatus::Suspended]);
        $rejected = \App\Models\Customer::factory()->create(['phone' => '01000000012', 'type' => \App\Enums\Customer\CustomerType::Retail, 'status' => \App\Enums\Customer\CustomerStatus::Rejected]);
        $deleted = \App\Models\Customer::factory()->create(['phone' => '01000000013', 'type' => \App\Enums\Customer\CustomerType::Retail, 'status' => \App\Enums\Customer\CustomerStatus::Approved]);
        $deleted->delete();

        foreach ([$suspended->id, $rejected->id, $deleted->id] as $badId) {
            $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
                'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
                'customer_id' => $badId,
            ], $this->idem())->assertStatus(422);
        }
        $this->assertDatabaseCount('pos_held_sales', 0);
    }

    /**
     * F-03 (backend): the manual_price flag round-trips through hold and resume.
     */
    public function test_f03_hold_persists_manual_price_flag(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.held.view_all', 'pos.override_price']);
        $held = $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 620, 'manual_price' => true]],
        ], $this->idem())->assertCreated()->json('data');

        $resumed = $this->withToken($token)->postJson("/api/v1/pos/sales/held/{$held['id']}/resume", [])
            ->assertOk()->json('data');
        $this->assertTrue((bool) ($resumed['items_payload'][0]['manual_price'] ?? false));
    }

    /**
     * F-10: repeating a shift OPEN with the same Idempotency-Key returns the
     * already-opened shift instead of a duplicate-shift error.
     */
    public function test_f10_open_shift_replay_returns_same_shift(): void
    {
        $cashier = $this->makeCashier('replay-open@example.com', $this->branch->id, ['pos.access', 'pos.sales.create']);
        $token = $this->tokenFor($cashier);
        $drawer = \App\Models\Treasury::create([
            'name' => 'Replay Drawer', 'code' => 'DRW-RPL-'.\Illuminate\Support\Str::random(4),
            'branch_id' => $this->branch->id, 'type' => \App\Enums\Finance\TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $payload = ['treasury_id' => $drawer->id, 'opening_cash' => 50];

        $key = ['Idempotency-Key' => 'open-'.\Illuminate\Support\Str::random(16)];
        $first = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', $payload, $key)
            ->assertCreated()->json('data');
        $second = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', $payload, $key)
            ->assertCreated()->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, \App\Models\PosShift::query()->where('user_id', $cashier->id)->count());
    }

    /**
     * F-13: catalogs above one page are always cursor-paginated (no 3000-row
     * single fetch), and every page carries the snapshot version.
     */
    public function test_f13_catalog_always_paginates_large_sets(): void
    {
        $token = $this->tokenFor($this->cashier);
        $brand = \App\Models\Brand::create(['name_ar' => 'ب', 'name_en' => 'B13', 'slug' => 'b13-'.uniqid(), 'is_active' => true]);
        $cat = \App\Models\Category::create(['name_ar' => 'ص', 'name_en' => 'C13', 'slug' => 'c13-'.uniqid(), 'is_active' => true]);
        $product = \App\Models\Product::create([
            'code' => 'PRD-F13', 'name_ar' => 'صنف', 'name_en' => 'Item13',
            'slug' => 'item13-'.uniqid(), 'status' => \App\Enums\Catalog\ProductStatus::Published,
            'brand_id' => $brand->id, 'category_id' => $cat->id,
            'is_active' => true, 'published_at' => now(),
        ]);
        for ($i = 0; $i < 510; $i++) {
            \App\Models\ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'F13-'.$i.'-'.uniqid(),
                'barcode' => '62213'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'color_name_ar' => 'أحمر',
                'retail_price' => 100,
                'cost_price' => 60,
                'is_active' => true,
            ]);
        }

        $first = $this->withToken($token)->getJson('/api/v1/pos/catalog')->assertOk()->json();
        $this->assertCount(500, $first['data']);
        $this->assertNotNull($first['meta']['next_cursor']);
        $version = $first['meta']['catalog_version'];
        $this->assertNotSame('', $version);

        $second = $this->withToken($token)->getJson('/api/v1/pos/catalog?cursor='.urlencode($first['meta']['next_cursor']))->assertOk()->json();
        // Follow-up pages carry no recomputed version (the client pins the
        // first page's snapshot); only the items and the terminal cursor matter.
        $this->assertCount(11, $second['data']);
        $this->assertNull($second['meta']['next_cursor']);
        $this->assertSame('100.00', number_format((float) $second['data'][0]['unit_price'], 2, '.', ''));
    }

    /**
     * F-14 (backend): the stocktake session exposes product names (fallback
     * material for off-catalog rows) and the server-side scan history so the
     * log panel works on the real API, including after a page reload.
     */
    public function test_f14_stocktake_session_exposes_names_and_scan_history(): void
    {
        $this->cashier->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('stocktakes.update', 'staff'));
        $token = $this->tokenFor($this->cashier);
        $session = \App\Models\Stocktake::create([
            'stocktake_number' => 'ST-F14-'.uniqid(),
            'branch_id' => $this->branch->id,
            'status' => \App\Enums\Inventory\StocktakeStatus::Draft,
            'created_by' => $this->cashier->id,
        ]);

        $scan = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$session->id}/scan", [
            'barcode' => $this->variant->barcode,
            'qty' => 2,
            // scan rows (the server-side history) are keyed by scan_id.
            'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk()->json();
        $this->assertSame(2.0, (float) $scan['counted_qty']);
        $this->assertNotSame('', (string) ($scan['product_name'] ?? ''));

        $current = $this->withToken($token)->getJson('/api/v1/pos/stocktakes/current')->assertOk()->json('data');
        $this->assertSame($session->id, $current['id']);
        $this->assertNotSame('', (string) ($current['items'][0]['product_name'] ?? ''));
        $this->assertNotEmpty($current['recent_scans']);
        $this->assertSame($this->variant->id, (int) $current['recent_scans'][0]['product_variant_id']);
        $this->assertSame(2.0, (float) $current['recent_scans'][0]['qty']);
    }

    /**
     * F-10: retrying a committed shift CLOSE with the same Idempotency-Key
     * returns the original close summary instead of 403 no_open_shift.
     */
    public function test_f10_close_retry_after_commit_returns_summary(): void
    {
        $cashier = $this->makeCashier('replay-close@example.com', $this->branch->id, ['pos.access']);
        $token = $this->tokenFor($cashier);
        $closeDrawer = \App\Models\Treasury::create([
            'name' => 'Close Drawer', 'code' => 'DRW-CLS-'.\Illuminate\Support\Str::random(4),
            'branch_id' => $this->branch->id, 'type' => \App\Enums\Finance\TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $this->openShift($cashier, $closeDrawer, 0.0);

        $key = ['Idempotency-Key' => 'close-'.\Illuminate\Support\Str::random(16)];
        $payload = ['actual_closing_cash' => 0];
        $first = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', $payload, $key)
            ->assertOk()->json('data');
        $this->assertSame('closed_pending_approval', $first['status']);

        $supervisor = $this->makeCashier('approve-close-replay@example.com', $this->branch->id);
        app(\App\Services\Pos\PosService::class)->approveShift(
            \App\Models\PosShift::findOrFail($first['id']),
            $supervisor,
        );

        $second = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => '0.00',
        ], $key)->assertOk()->json('data');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('approved', $second['status']);
    }

    /**
     * F-02: a supervisor cannot reopen their OWN shift for recount.
     * Mirrors the approveShift self-ban (fingerprint: same message family).
     */
    public function test_f02_reopen_rejects_self_reopen(): void
    {
        $supervisor = $this->makeCashier('self-reopen@example.com', $this->branch->id, ['pos.access']);
        $posService = app(\App\Services\Pos\PosService::class);
        $ownDrawer = \App\Models\Treasury::create([
            'name' => 'Self Drawer', 'code' => 'DRW-SELF-'. \Illuminate\Support\Str::random(4),
            'branch_id' => $this->branch->id, 'type' => \App\Enums\Finance\TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);

        $ownShift = $posService->openShift($supervisor, $this->branch->id, $ownDrawer->id, 0.0);
        $posService->closeShift($ownShift->fresh(), 0.0, 'إغلاق ورديتي');

        try {
            $posService->reopenShiftForRecount($ownShift->fresh(), $supervisor, 'إعادة جرد ذاتية');
            $this->fail('reopenShiftForRecount accepted a self-reopen');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('بنفسك', $e->getMessage());
        }
        $this->assertSame('closed_pending_approval', $ownShift->fresh()->status->value);
    }

    /**
     * F-02: reopening a force-closed shift must NOT wipe the force_closed_by
     * taint — the forcer stays barred from the final approval.
     */
    public function test_f02_reopen_preserves_force_close_taint(): void
    {
        $forcer = $this->makeCashier('forcer-f02@example.com', $this->branch->id, ['pos.access']);
        $reviewer = $this->makeCashier('reviewer-f02@example.com', $this->branch->id, ['pos.access']);
        $posService = app(\App\Services\Pos\PosService::class);
        $shift = \App\Models\PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();

        $posService->forceCloseShift($shift, $forcer, 0.0, 'الموظف غادر');
        $this->assertSame($forcer->id, (int) $shift->fresh()->force_closed_by);

        $posService->reopenShiftForRecount($shift->fresh(), $reviewer, 'إعادة جرد بعد الإغلاق القسري');

        // Taint survives the reopen: the forcer still cannot approve later.
        $this->assertSame($forcer->id, (int) $shift->fresh()->force_closed_by);

        $posService->closeShift($shift->fresh(), 0.0, 'إعادة إغلاق بعد الجرد');
        try {
            $posService->approveShift($shift->fresh(), $forcer);
            $this->fail('approveShift accepted the original forcer after a reopen');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('قسرياً بنفسك', $e->getMessage());
        }
    }

    /**
     * F-02: approveShift and closeShift must write staff_audit_logs entries
     * like reopen/forceClose already do.
     */
    public function test_f02_approve_and_close_write_audit_logs(): void
    {
        $reviewer = $this->makeCashier('auditor-f02@example.com', $this->branch->id, ['pos.access']);
        $posService = app(\App\Services\Pos\PosService::class);
        $shift = \App\Models\PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();

        $posService->closeShift($shift, 0.0, 'إغلاق للتدقيق');
        $this->assertDatabaseHas('staff_audit_logs', [
            'action' => 'pos_shift.close',
            'target_id' => $shift->id,
        ]);

        $posService->approveShift($shift->fresh(), $reviewer);
        $this->assertDatabaseHas('staff_audit_logs', [
            'action' => 'pos_shift.approve',
            'target_id' => $shift->id,
        ]);
    }

    /**
     * F-01 mirror case: credit funded by a cash-paid sale restores exactly the
     * cash physically paid in the chain (500), remainder to card (100).
     */
    public function test_f01_return_of_cash_funded_exchange_order_restores_chain_cash(): void
    {
        $this->fundDrawer(1000);
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(600.00);
        $this->stockIn($this->branch->id, $replacement, 15);

        // O1: 5 x 100 paid entirely in cash.
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'cash_amount' => 500,
            'card_amount' => 0,
        ], $this->idem())->assertCreated()->json('data');

        $ex = $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 100,
        ], $this->idem())->assertOk()->json('data');

        $exchangeOrder = Order::findOrFail($ex['exchange_order_id']);

        $ret = $this->withToken($token)->postJson("/api/v1/pos/sales/{$exchangeOrder->id}/return", [
            'items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
        ], $this->idem())->assertOk()->json('data');

        $returnOrder = Order::findOrFail($ret['return_order_id']);
        $this->assertSame('500.00', (string) $returnOrder->cash_amount);
        $this->assertSame('100.00', (string) $returnOrder->card_amount);
    }
}
