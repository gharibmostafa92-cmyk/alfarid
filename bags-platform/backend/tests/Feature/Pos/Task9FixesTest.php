<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Finance\PosShiftStatus;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Pos\PosService;
use App\Services\Pos\TreasuryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Regression & TDD test suite for task9 roadmap fixes.
 */
class Task9FixesTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
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

    // ── CRITICAL-02: Blind close without notes succeeds; approve requires variance note ──

    public function test_critical02_blind_cashier_can_close_with_variance_without_notes_and_approve_requires_note(): void
    {
        $token = $this->tokenFor($this->cashier);
        $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated();

        // Blind cashier does NOT hold pos_shifts.view_expected and sends NO notes
        // even though physical count (85) differs from expected (100).
        // Closing must succeed without leaking variance or acting as a 422 oracle.
        $closeRes = $this->as($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 85.00,
        ]);

        $closeRes->assertOk()
            ->assertJsonPath('data.status', 'closed_pending_approval')
            ->assertJsonMissingPath('data.expected_closing_cash')
            ->assertJsonMissingPath('data.cash_difference');

        $shiftId = (int) $closeRes->json('data.id');
        $reviewer = $this->makeSupervisor('c02-reviewer@example.com');
        $adminToken = $this->adminToken($reviewer);

        // Approving a shift with variance != 0 and no explanatory note must fail (422).
        $this->as($adminToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve", [])
            ->assertStatus(422);
        $this->assertSame(PosShiftStatus::ClosedPendingApproval, PosShift::findOrFail($shiftId)->status);

        // Passing supervisor reconciliation notes unlocks approval.
        $this->as($adminToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve", [
            'notes' => 'تم التحقق من العجز بقيمة 15 جنيه واعتماد التسوية',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertStringContainsString(
            'تم التحقق من العجز',
            (string) PosShift::findOrFail($shiftId)->notes,
        );
    }

    // ── HIGH-01: Drawer ledger & shift totals reconciliation on settlement/approval ──

    public function test_high01_settle_rejects_drawer_balance_divergence_from_expected_cash(): void
    {
        $token = $this->tokenFor($this->cashier);
        $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated();

        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        $closed = app(PosService::class)->closeShift($shift, 100.00, null);
        $reviewer = $this->makeSupervisor('h01-reviewer@example.com');

        // Simulate silent treasury ledger drift (drawer balance != shift expected_closing_cash).
        DB::table('treasuries')->where('id', $this->drawer->id)->update(['current_balance' => 140.00]);

        try {
            app(TreasuryService::class)->settleShiftToMainSafe($closed->fresh(), $reviewer->id);
            $this->fail('settleShiftToMainSafe must reject drawer balance divergence.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        // Neither settled_at nor main safe balance may be mutated.
        $this->assertNull($closed->fresh()->settled_at);
        $this->assertSame('1000.00', $this->safe->fresh()->current_balance);
    }

    public function test_high01_approve_rejects_shift_whose_computed_expected_cash_drifted_after_close(): void
    {
        $token = $this->tokenFor($this->cashier);
        $sale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated()->json('data');

        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        $this->as($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 100.00,
        ])->assertOk();

        // Mutate an order bound to the closed shift after closeShift computed totals.
        Order::query()->whereKey($sale['id'])->update([
            'subtotal' => 150.00,
            'grand_total' => 150.00,
            'cash_amount' => 150.00,
        ]);

        $reviewer = $this->makeSupervisor('h01-drift@example.com');
        $adminToken = $this->adminToken($reviewer);

        $this->as($adminToken)->postJson("/api/v1/admin/shifts/{$shift->id}/approve")
            ->assertStatus(409);

        $this->assertSame(PosShiftStatus::ClosedPendingApproval, $shift->fresh()->status);
        $this->assertNull($shift->fresh()->settled_at);
    }

    // ── CRITICAL-04: Atomic exchange rolls back replacement order when return fails ──

    public function test_critical04_service_process_exchange_locks_and_rolls_back_atomically(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(150.00);
        $this->stockIn($this->branch->id, $replacement, 5);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertCreated()->json('data');

        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        $saleModel = Order::findOrFail($sale['id']);

        $drawerBefore = (float) $this->drawer->fresh()->current_balance;
        $stockBefore = (float) StockLevel::query()
            ->where('product_variant_id', $replacement->id)
            ->where('branch_id', $this->branch->id)
            ->sum('on_hand');
        $ordersBefore = Order::count();

        // Direct service invocation (PosService::processExchange) must lock and
        // wrap createSale + processReturn atomically in one transaction.
        $unpurchased = $this->makeVariant(90.00);
        try {
            app(PosService::class)->processExchange($shift, $saleModel, $this->cashier, [
                'returned_items' => [['product_variant_id' => $unpurchased->id, 'qty' => 1]],
                'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
                'cash_amount' => 150.00,
                'card_amount' => 0,
            ]);
            $this->fail('processExchange should have failed when returned item is missing from original sale.');
        } catch (BusinessException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($drawerBefore, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame($stockBefore, (float) StockLevel::query()
            ->where('product_variant_id', $replacement->id)
            ->where('branch_id', $this->branch->id)
            ->sum('on_hand'));
    }

    // ── HIGH-02: Live quote allows price override without reason; createSale enforces reason ──

    public function test_high02_quote_allows_override_without_reason_while_sale_enforces_it(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.override_price', 'pos.override_price_below_cost']);

        $this->as($token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 85.00]],
            'override_price' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.grand_total', '85.00');

        // Actual sale still requires override_reason.
        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 85.00]],
            'override_price' => true,
            'cash_amount' => 85.00,
        ], $this->idem())->assertStatus(422);
    }

    public function test_quote_rejects_fractional_cent_unit_prices(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.override_price', 'pos.override_price_below_cost']);

        $this->as($token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 3, 'unit_price' => 85.005]],
            'override_price' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');
    }

    // ── HIGH-05: Batch Sale COGS lookup with StockMovementType::Sale + cost_price fallback ──

    public function test_high05_process_return_falls_back_to_variant_cost_price_and_batches_sale_movements(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $v2 = $this->makeVariant(200.00);
        $v2->update(['cost_price' => 77.50]);
        $this->stockIn($this->branch->id, $v2, 5);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'cash_amount' => 100.00,
            'card_amount' => 0,
        ], $this->idem())->assertCreated()->json('data');

        // Append v2 to the order with NO Sale StockMovement (simulating legacy/null cost record)
        // to verify variant->cost_price fallback without violating stock_movements immutability.
        \App\Models\OrderItem::create([
            'order_id' => $sale['id'],
            'product_variant_id' => $v2->id,
            'product_name_snapshot' => 'Fallback Item',
            'sku_snapshot' => $v2->sku,
            'allocated_branch_id' => $this->branch->id,
            'qty' => 1,
            'unit_price' => 200.00,
            'discount_amount' => 0,
            'line_total' => 200.00,
        ]);
        Order::query()->whereKey($sale['id'])->update([
            'subtotal' => 300.00,
            'grand_total' => 300.00,
            'cash_amount' => 300.00,
        ]);

        // Fund drawer so 300 cash return has balance.
        $this->drawer->increment('current_balance', 300);

        $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
                ['product_variant_id' => $v2->id, 'qty' => 1],
            ],
        ], $this->idem())->assertOk();

        $returnMovement = StockMovement::query()
            ->where('reference_id', $sale['id'])
            ->where('product_variant_id', $v2->id)
            ->where('type', StockMovementType::SaleReturn)
            ->firstOrFail();

        $this->assertSame('77.5000', (string) $returnMovement->unit_cost);
    }

    // ── MEDIUM-01 & LOW-01: Customer response includes status & POS quick create omits password hash ──

    public function test_medium01_and_low01_pos_customer_includes_status_and_uses_nullable_password(): void
    {
        $token = $this->tokenFor($this->cashier);

        $created = $this->as($token)->postJson('/api/v1/pos/customers', [
            'name' => 'تاجر جملة جديد',
            'phone' => '01098765432',
            'type' => 'wholesale',
        ])->assertCreated();

        $created->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'wholesale');

        $customerId = (int) $created->json('data.id');
        $this->assertNull(Customer::findOrFail($customerId)->password);

        // Unified login on passwordless POS customer fails safely with 401 (never crashes).
        $this->postJson('/api/v1/auth/login', [
            'login' => '01098765432',
            'password' => 'anything',
        ])->assertStatus(401);

        // Index endpoint also exposes status.
        $this->as($token)->getJson('/api/v1/pos/customers?q=01098765432')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending');
    }

    // ── MEDIUM-03: Numeric barcode miss skips LIKE scan; Category update invalidates catalog cache ──

    public function test_medium03_numeric_barcode_lookup_skips_like_scan_and_category_updates_catalog_version(): void
    {
        $pos = app(PosService::class);
        Cache::flush();

        $v1 = $pos->computeCatalogVersion($this->branch->id);

        // Updating a category must produce a new catalog version immediately.
        Category::query()->first()->update(['name_ar' => 'شنط محدثة']);
        $v2 = $pos->computeCatalogVersion($this->branch->id);
        $this->assertNotSame($v1, $v2);

        // Repeated call hits cache without executing SQL queries.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $v2Cached = $pos->computeCatalogVersion($this->branch->id);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($v2, $v2Cached);
        $this->assertCount(0, $queries);

        // Purely numeric non-existent barcode must NOT run LIKE '%...%' on products.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $miss = $pos->lookupProducts('999888777666', $this->branch->id);
        $lookupQueries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        $this->assertSame([], $miss);
        $this->assertStringNotContainsString('LIKE', strtoupper($lookupQueries));
    }

    // ── HIGH-04 & MEDIUM-04: Held lease + full customer + idempotency + throttles ──

    public function test_high04_held_and_resume_preserve_row_with_lease_and_full_customer_until_sale(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.held.view_all']);
        $customer = Customer::create([
            'name' => 'مكرر الاسم',
            'phone' => '01012349988',
            'password' => null,
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Pending,
        ]);

        $holdKey = $this->idem();
        $holdPayload = [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'customer_id' => $customer->id,
            'notes' => 'عميل ينتظر',
        ];
        $held = $this->as($token)->postJson('/api/v1/pos/sales/hold', $holdPayload, $holdKey)
            ->assertCreated()->json('data');
        $heldId = (int) $held['id'];

        // Replaying the same Idempotency-Key does not create a second held sale.
        $replayedHold = $this->as($token)->postJson('/api/v1/pos/sales/hold', $holdPayload, $holdKey)
            ->assertStatus(201)->json('data');
        $this->assertSame($heldId, (int) $replayedHold['id']);
        $this->assertDatabaseCount('pos_held_sales', 1);

        // Changing payload under the same key fails with 409.
        $this->as($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ], $holdKey)->assertStatus(409);

        // Listing held carts exposes full customer metadata (status + type).
        $this->as($token)->getJson('/api/v1/pos/sales/held')
            ->assertOk()
            ->assertJsonPath('data.0.customer.id', $customer->id)
            ->assertJsonPath('data.0.customer.type', 'wholesale')
            ->assertJsonPath('data.0.customer.status', 'pending');

        // Resuming sets a soft lease (resumed_by / resumed_at) instead of deleting immediately.
        $resumed = $this->as($token)->postJson("/api/v1/pos/sales/held/{$heldId}/resume")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.type', 'wholesale')
            ->assertJsonPath('data.customer.status', 'pending');
        $this->assertNotNull($resumed->json('data.resumed_at'));

        $this->assertDatabaseHas('pos_held_sales', [
            'id' => $heldId,
            'resumed_by' => $this->cashier->id,
        ]);

        // The lease owner can recover a parked cart again before the lease expires.
        $this->as($token)->postJson("/api/v1/pos/sales/held/{$heldId}/resume", [], $this->idem())
            ->assertOk();

        // Completing checkout atomically deletes the held row.
        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'customer_id' => $customer->id,
            'cash_amount' => 100.00,
            'held_sale_id' => $heldId,
        ], $this->idem())->assertCreated();

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $heldId]);
    }

    public function test_medium04_pos_api_throttles_and_receive_transfer_idempotency(): void
    {
        $this->cashier->givePermissionTo(
            Permission::findOrCreate('pos.sales.refund', 'staff'),
            Permission::findOrCreate('transfers.receive', 'staff'),
        );
        $token = $this->tokenFor($this->cashier);

        // POS group exposes throttle:pos-api limit (180/min).
        $cat = $this->as($token)->getJson('/api/v1/pos/catalog')->assertOk();
        $this->assertSame('180', (string) $cat->headers->get('X-RateLimit-Limit'));

        // Narrower 30/min throttle on GET sales/lookup.
        $lookup = $this->as($token)->getJson('/api/v1/pos/sales/lookup?number=MISSING-001');
        $lookup->assertStatus(404);
        $this->assertSame('30', (string) $lookup->headers->get('X-RateLimit-Limit'));

        // Narrower 30/min throttle on POST customers.
        $cust = $this->as($token)->postJson('/api/v1/pos/customers', [
            'name' => 'ثروتل اختبار',
            'phone' => '01099988877',
            'type' => 'retail',
        ])->assertCreated();
        $this->assertSame('30', (string) $cust->headers->get('X-RateLimit-Limit'));

        // Idempotency-Key support on receiveTransfer.
        $otherBranch = \App\Models\Branch::create([
            'name' => 'Source Branch', 'code' => 'SRC-'.uniqid(), 'is_active' => true, 'type' => 'branch',
        ]);
        $fromLoc = \App\Models\Location::create([
            'branch_id' => $otherBranch->id, 'name' => 'From', 'code' => 'FL-'.uniqid(), 'type' => \App\Enums\Inventory\LocationType::Shelf, 'is_active' => true,
        ]);
        $toLoc = \App\Models\Location::query()->where('branch_id', $this->branch->id)->first()
            ?? \App\Models\Location::create([
                'branch_id' => $this->branch->id, 'name' => 'To', 'code' => 'TL-'.uniqid(), 'type' => \App\Enums\Inventory\LocationType::Shelf, 'is_active' => true,
            ]);
        $transfer = \App\Models\StockTransfer::create([
            'transfer_number' => 'TRF-'.uniqid(),
            'from_branch_id' => $otherBranch->id,
            'to_branch_id' => $this->branch->id,
            'status' => \App\Enums\Inventory\TransferStatus::Shipped,
            'created_by' => $this->cashier->id,
            'shipped_at' => now(),
        ]);
        $item = \App\Models\StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_variant_id' => $this->variant->id,
            'from_location_id' => $fromLoc->id,
            'to_location_id' => $toLoc->id,
            'requested_qty' => 2,
            'approved_qty' => 2,
            'shipped_qty' => 2,
            'received_qty' => 0,
        ]);
        app(\App\Services\Inventory\InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'location_id' => $toLoc->id,
            'type' => \App\Enums\Inventory\StockMovementType::TransferInTransit,
            'affected_bucket' => \App\Enums\Inventory\StockBucket::InTransit,
            'quantity' => 2,
            'reference_type' => \App\Models\StockTransfer::class,
            'reference_id' => $transfer->id,
            'created_by' => $this->cashier->id,
        ]);

        $rcvKey = $this->idem();
        $this->as($token)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'expected_version' => $transfer->fresh()->version,
            'items' => [['id' => $item->id, 'received_qty' => 2]],
        ], $rcvKey)->assertOk();

        // Payload tampering under the same Idempotency-Key rejects cleanly with 409.
        $this->as($token)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'expected_version' => $transfer->fresh()->version,
            'items' => [['id' => $item->id, 'received_qty' => 1]],
        ], $rcvKey)->assertStatus(409);
    }
}
