<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\TransferService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

/**
 * task.txt §2 scenarios 2 (oversell), 11 (shift middleware), 12 (branch
 * isolation), 13 (permission matrix), 15 (numbering), 16 (treasury),
 * 27 (sale input limits) + related §3 edge cases.
 */
class TaskTxtPosScenariosCTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    public function test_02_oversell_last_piece_rejected_without_negative_stock(): void
    {
        // Stock of exactly 1: first sale wins, second is rejected.
        $one = $this->makeVariant(100.00);
        $this->stockIn($this->branch->id, $one, 1);

        $drawer2 = Treasury::create([
            'name' => 'Drawer 2', 'code' => 'DRW-C2',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $cashier2 = $this->makeCashier('c2@example.com', $this->branch->id, ['pos.access']);
        $token2 = $this->openShift($cashier2, $drawer2, 0.0);
        $token1 = $this->token;

        Auth::forgetGuards();
        $this->withToken($token1)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1, 0.0, $one->id), $this->idem())->assertStatus(201);
        Auth::forgetGuards();
        $second = $this->withToken($token2)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1, 0.0, $one->id), $this->idem());
        $second->assertStatus(422);
        $this->assertMatchesRegularExpression('/المتاحة|تغير/u', $second->json('message'));

        $this->assertSame(0.0, (float) StockLevel::query()
            ->where('product_variant_id', $one->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand'));
        $this->assertGreaterThanOrEqual(0.0, (float) StockLevel::query()
            ->where('product_variant_id', $one->id)
            ->where('branch_id', $this->branch->id)->min('on_hand'));

        // Ledger chain: balance_after of each movement equals balance_before of the next for the same key.
        $movements = StockMovement::query()
            ->where('product_variant_id', $one->id)
            ->where('branch_id', $this->branch->id)
            ->orderBy('id')->get();
        $this->assertNotEmpty($movements);
        $prevAfter = [];
        foreach ($movements as $m) {
            $key = $m->product_variant_id.'|'.$m->branch_id.'|'.($m->location_id ?? 'null').'|'.$m->affected_bucket->value;
            if (array_key_exists($key, $prevAfter)) {
                $this->assertSame((float) $prevAfter[$key], (float) $m->balance_before, "chain broken at movement {$m->id}");
            }
            $prevAfter[$key] = (float) $m->balance_after;
        }
    }

    public function test_02_oversell_distributed_locations_single_winner(): void
    {
        $two = $this->makeVariant(100.00);

        $loc1 = Location::create(['branch_id' => $this->branch->id, 'name' => 'A1', 'code' => 'LOC-A1', 'type' => 'shelf', 'is_active' => true]);
        $loc2 = Location::create(['branch_id' => $this->branch->id, 'name' => 'A2', 'code' => 'LOC-A2', 'type' => 'shelf', 'is_active' => true]);
        foreach ([$loc1, $loc2] as $loc) {
            app(InventoryService::class)->recordMovement([
                'product_variant_id' => $two->id,
                'branch_id' => $this->branch->id,
                'location_id' => $loc->id,
                'type' => \App\Enums\StockMovementType::AdjustmentIn,
                'affected_bucket' => \App\Enums\StockBucket::OnHand,
                'quantity' => 1,
                'created_by' => $this->cashier->id,
            ]);
        }

        $drawer2 = Treasury::create([
            'name' => 'Drawer 2b', 'code' => 'DRW-C2B',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $cashier2 = $this->makeCashier('c2b@example.com', $this->branch->id, ['pos.access']);
        $token2 = $this->openShift($cashier2, $drawer2, 0.0);

        // Total across locations is 2: first qty-2 sale wins, second is rejected.
        Auth::forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(200, 0, 2, 0.0, $two->id), $this->idem())->assertStatus(201);
        Auth::forgetGuards();
        $this->withToken($token2)->postJson('/api/v1/pos/sales', $this->salePayload(200, 0, 2, 0.0, $two->id), $this->idem())->assertStatus(422);
        $this->assertSame(0.0, (float) StockLevel::query()
            ->where('product_variant_id', $two->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand'));
    }

    public function test_11_shift_middleware_matrix(): void
    {
        // No branch: 403 without a machine code.
        $noBranch = User::create([
            'name' => 'No Branch', 'email' => 'nobranch@example.com',
            'phone' => '+201055556666', 'password' => Hash::make('secret123'),
            'branch_id' => null, 'is_active' => true,
        ]);
        $noBranch->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        Auth::forgetGuards();
        $noBranchToken = $noBranch->createToken('pos', ['pos'])->plainTextToken;
        $res = $this->withToken($noBranchToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $res->assertStatus(403);
        $this->assertArrayNotHasKey('code', $res->json());

        // No shift: 403 with no_open_shift.
        $lonely = $this->makeCashier('lonely@example.com', $this->branch->id, ['pos.access']);
        Auth::forgetGuards();
        $lonelyToken = $lonely->createToken('pos', ['pos'])->plainTextToken;
        $this->withToken($lonelyToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertStatus(403)->assertJsonPath('code', 'no_open_shift');

        // Branch changed mid-shift: sale 403, and reopening is refused (stuck state needs a decision).
        $this->cashier->update(['branch_id' => $this->branchB->id]);
        $this->cashier->refresh();
        Auth::forgetGuards();
        app()->forgetScopedInstances();
        $movedToken = $this->cashier->createToken('pos-moved', ['pos'])->plainTextToken;
        $this->withToken($movedToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertStatus(403)->assertJsonPath('code', 'no_open_shift');
        $this->withToken($movedToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawerB->id, 'opening_cash' => 0,
        ])->assertStatus(422);

        // Move back and close: pending shifts also block sales with the same code.
        $this->cashier->update(['branch_id' => $this->branch->id]);
        $this->cashier->refresh();
        Auth::forgetGuards();
        app()->forgetScopedInstances();
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['actual_closing_cash' => 0])->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())
            ->assertStatus(403)->assertJsonPath('code', 'no_open_shift');
    }

    public function test_12_branch_isolation_across_pos_resources(): void
    {
        $tokenA = $this->tokenFor($this->cashier, ['pos.sales.refund', 'transfers.create', 'transfers.receive', 'stocktakes.update']);

        // Branch-B sale, held cart, transfer, and stocktake fixtures.
        $this->stockIn($this->branchB->id, $this->variant, 5, $this->cashierB->id);
        $tokenB = $this->openShift($this->cashierB, $this->drawerB, 0.0);
        $this->tokenFor($this->cashierB, ['pos.sales.refund', 'transfers.create']);
        Auth::forgetGuards();
        $saleB = $this->withToken($tokenB)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash_amount' => 100, 'card_amount' => 0,
        ], $this->idem())->assertStatus(201);
        $saleBId = $saleB->json('data.id');

        Auth::forgetGuards();
        $heldB = $this->withToken($tokenB)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(201);
        $heldBId = $heldB->json('data.id');

        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $this->branch->id,
            'to_branch_id' => $this->branchB->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $this->cashierB->id, 'iso-'.uniqid());
        $stocktakeB = app(StocktakeService::class)->create(['branch_id' => $this->branchB->id, 'items' => []], $this->cashierB->id);

        Auth::forgetGuards();
        // Held resume/delete of the other branch: rejected, cart survives.
        $this->withToken($tokenA)->postJson("/api/v1/pos/sales/held/{$heldBId}/resume")->assertStatus(403);
        $this->withToken($tokenA)->deleteJson("/api/v1/pos/sales/held/{$heldBId}")->assertStatus(403);
        $this->assertNotNull(\App\Models\PosHeldSale::find($heldBId));

        // Lookup/return of the other branch's invoice: rejected, nothing changes.
        $this->withToken($tokenA)->getJson('/api/v1/pos/sales/lookup?number='.$saleB->json('data.order_number'))->assertStatus(403);
        $this->withToken($tokenA)->postJson("/api/v1/pos/sales/{$saleBId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(403);
        $this->assertSame(0, Order::query()->where('return_of_order_id', $saleBId)->count());

        // Receiving a transfer bound for B from A: rejected.
        $this->withToken($tokenA)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'items' => [['id' => $transfer->items->first()->id, 'received_qty' => 1]],
        ])->assertStatus(403);

        // Scanning B's stocktake from A: rejected.
        $this->withToken($tokenA)->postJson("/api/v1/pos/stocktakes/{$stocktakeB->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertStatus(403);

        // Branch-scoped manager cannot read B's shift.
        $manager = $this->makeCashier('mgr-a@example.com', $this->branch->id, []);
        $manager->givePermissionTo(Permission::findOrCreate('pos_shifts.view', 'staff'));
        Auth::forgetGuards();
        $managerToken = $manager->createToken('admin')->plainTextToken;
        $shiftA = PosShift::query()->where('user_id', $this->cashier->id)->sole();
        $this->withToken($managerToken)->getJson("/api/v1/admin/shifts/{$shiftA->id}")
            ->assertOk()->assertJsonPath('data.id', $shiftA->id);
        $shiftB = \App\Models\PosShift::query()->where('user_id', $this->cashierB->id)->firstOrFail();
        $this->withToken($managerToken)->getJson("/api/v1/admin/shifts/{$shiftB->id}")->assertStatus(403);

        $globalManager = $this->makeCashier('global-manager@example.test', $this->branch->id, [
            'pos_shifts.view', 'inventory.all_branches',
        ]);
        $globalToken = $globalManager->createToken('admin')->plainTextToken;
        Auth::forgetGuards();
        app()->forgetScopedInstances();
        $this->withToken($globalToken)->getJson("/api/v1/admin/shifts/{$shiftB->id}")
            ->assertOk()->assertJsonPath('data.id', $shiftB->id);

        // A same-branch cashier can look up B's invoice; an all-branches user in A can too.
        Auth::forgetGuards();
        $this->withToken($tokenB)->getJson('/api/v1/pos/sales/lookup?number='.$saleB->json('data.order_number'))
            ->assertOk()->assertJsonPath('data.id', $saleBId);
        $roamer = $this->makeCashier('roamer@example.com', $this->branch->id, ['pos.access', 'inventory.all_branches', 'pos.sales.refund']);
        $openToken = $this->openShift($roamer, Treasury::create([
            'name' => 'Roam Drawer', 'code' => 'DRW-ROAM',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]), 0.0);
        Auth::forgetGuards();
        $this->withToken($openToken)->getJson('/api/v1/pos/sales/lookup?number='.$saleB->json('data.order_number'))
            ->assertOk()->assertJsonPath('data.id', $saleBId);
    }

    public function test_13_permission_matrix(): void
    {
        $baseToken = $this->token; // pos.access only

        // Without pos.sales.refund: lookup/return/exchange are 403 with no side effects.
        Auth::forgetGuards();
        $sale = $this->withToken($baseToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())->assertStatus(201);
        $saleId = $sale->json('data.id');
        $this->withToken($baseToken)->getJson('/api/v1/pos/sales/lookup?number='.$sale->json('data.order_number'))->assertStatus(403);
        $this->withToken($baseToken)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(403);
        $this->withToken($baseToken)->postJson("/api/v1/pos/sales/{$saleId}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'cash_amount' => 100, 'card_amount' => 0,
        ], $this->idem())->assertStatus(403);
        $this->assertSame(0, Order::query()->where('return_of_order_id', $saleId)->count());
        Auth::forgetGuards();
        $refundToken = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $this->withToken($refundToken)->getJson('/api/v1/pos/sales/lookup?number='.$sale->json('data.order_number'))->assertOk();

        // transfers.create / transfers.receive.
        $this->withToken($baseToken)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $this->branchB->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $this->idem())->assertStatus(403);
        $this->stockIn($this->branchB->id, $this->variant, 10, $this->cashierB->id);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $this->branchB->id,
            'to_branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $this->cashier->id, 'perm-'.uniqid());
        // Ship via service so receive is reachable, then call receive without the permission.
        $hq = $this->makeCashier('hq-t@example.com', null, []);
        $hq->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        app(TransferService::class)->approve($transfer->fresh(), [], $hq->id);
        app(TransferService::class)->ship($transfer->fresh(), [], $hq->id);
        $transfer = $transfer->fresh();
        $this->withToken($baseToken)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'items' => [['id' => $transfer->items->first()->id, 'received_qty' => 1]],
        ])->assertStatus(403);
        Auth::forgetGuards();
        $recvToken = $this->tokenFor($this->cashier, ['transfers.receive']);
        $this->withToken($recvToken)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'items' => [['id' => $transfer->items->first()->id, 'received_qty' => 1]],
        ])->assertOk();

        // stocktakes.update + system_qty visibility.
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $this->withToken($baseToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertStatus(403);
        Auth::forgetGuards();
        $scanToken = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $scan = $this->withToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertOk();
        $scan->assertJsonMissingPath('system_qty');
        Auth::forgetGuards();
        $approverToken = $this->tokenFor($this->cashier, ['stocktakes.approve']);
        $this->withToken($approverToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode, 'qty' => 1,
        ])->assertOk()->assertJsonStructure(['system_qty']);

        // pos_shifts.view / pos_shifts.approve on admin endpoints.
        $viewer = $this->makeCashier('viewer@example.com', $this->branch->id, []);
        Auth::forgetGuards();
        $viewerToken = $viewer->createToken('admin')->plainTextToken;
        $this->withToken($viewerToken)->getJson('/api/v1/admin/shifts')->assertStatus(403);
        $shiftId = \App\Models\PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail()->id;
        $this->withToken($viewerToken)->getJson("/api/v1/admin/shifts/{$shiftId}")->assertStatus(403);
        $viewer->givePermissionTo(Permission::findOrCreate('pos_shifts.view', 'staff'));
        Auth::forgetGuards();
        app()->forgetScopedInstances();
        $this->withToken($viewerToken)->getJson('/api/v1/admin/shifts')->assertOk();
        $this->withToken($viewerToken)->getJson("/api/v1/admin/shifts/{$shiftId}")->assertOk();
        $this->withToken($viewerToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve")->assertStatus(403);
    }

    public function test_15_document_numbers_unique_with_configured_prefix(): void
    {
        $tokenB = $this->openShift($this->cashierB, $this->drawerB, 0.0);
        $this->stockIn($this->branchB->id, $this->variant, 30, $this->cashierB->id);
        $numbers = [];
        $branchOf = [];
        for ($i = 0; $i < 12; $i++) {
            $token = $i % 2 === 0 ? $this->token : $tokenB;
            Auth::forgetGuards();
            $res = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
            $res->assertStatus(201);
            $numbers[] = $res->json('data.order_number');
            $branchOf[] = Order::findOrFail($res->json('data.id'))->allocated_branch_id;
        }
        $this->assertSame(count($numbers), count(array_unique($numbers)), 'order numbers must be unique under load');
        $this->assertContains($this->branch->id, $branchOf);
        $this->assertContains($this->branchB->id, $branchOf);

        $settings = app(\App\Services\Shared\SettingService::class);
        $prefix = (string) $settings->get('order_prefix', 'ORD-');
        foreach ($numbers as $number) {
            $this->assertStringStartsWith($prefix, $number);
        }

        // Direct sequence volume: shifts + treasury references stay unique.
        $shiftNumbers = [];
        for ($i = 0; $i < 10; $i++) {
            $shiftNumbers[] = $settings->generateNextNumber('shift');
        }
        $this->assertSame(count($shiftNumbers), count(array_unique($shiftNumbers)));
        $this->assertSame(count($numbers) + count($shiftNumbers), count(array_unique(array_merge($numbers, $shiftNumbers))));
    }

    public function test_16_treasury_never_negative_and_ordered_locks(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);

        // Locks are acquired in primary-key order (deadlock-safe by construction).
        $locks = app(\App\Services\Pos\TreasuryService::class)->lockTreasuries([$this->safe->id, $this->drawer->id]);
        $this->assertSame([$this->drawer->id, $this->safe->id], $locks->keys()->all());

        // Creator cannot approve their own treasury transfer (service + DB guard).
        $transfer = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $this->safe->id,
            'to_treasury_id' => $this->drawer->id,
            'amount' => 100,
        ], $this->cashier->id);
        $this->expectExceptionObject(new \App\Exceptions\BusinessException('يجب أن يعتمد تحويل الخزينة موظف آخر غير منشئه.'));
        app(\App\Services\Pos\TreasuryService::class)->approveTransfer($transfer, $this->cashier->id);
    }

    public function test_16_treasury_balance_chain_consistent(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())->assertStatus(201);
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(50, 50, 1), $this->idem())->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale->json('data.id')}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        $txns = TreasuryTransaction::query()->where('treasury_id', $this->drawer->id)->orderBy('id')->get();
        $this->assertNotEmpty($txns);
        $running = 0.0;
        foreach ($txns as $txn) {
            $running = round($running + (float) $txn->amount, 2);
            $this->assertSame($running, round((float) $txn->balance_after, 2), "balance chain broken at txn {$txn->id}");
        }
        $this->assertSame($running, round((float) $this->drawer->fresh()->current_balance, 2));
        $this->assertGreaterThanOrEqual(0.0, (float) $this->drawer->fresh()->current_balance);
    }

    public function test_27_sale_input_limits(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);

        // hold with 10k lines and unknown variant are accepted today (decision needed).
        $manyItems = array_fill(0, 10000, ['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $this->withToken($token)->postJson('/api/v1/pos/sales/hold', ['items' => $manyItems])->assertStatus(201);
        $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => 999999999, 'qty' => 1]],
        ])->assertStatus(201);

        // store with unknown customer proceeds with a null customer (decision needed).
        $res = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash_amount' => 100, 'card_amount' => 0,
            'customer_id' => 999999999,
        ], $this->idem());
        $res->assertStatus(201);
        $this->assertNull(Order::findOrFail($res->json('data.id'))->customer_id);

        // Duplicate lines whose sum exceeds stock: rejected.
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 30],
                ['product_variant_id' => $this->variant->id, 'qty' => 25],
            ],
            'payment_method' => 'cash', 'cash_amount' => 5500, 'card_amount' => 0,
        ], $this->idem())->assertStatus(422);

        // Fractional qty, oversized carts, inactive variants, long notes rejected.
        $this->withToken($token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1.5]],
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => array_fill(0, 101, ['product_variant_id' => $this->variant->id, 'qty' => 1]),
            'payment_method' => 'cash', 'cash_amount' => 10100, 'card_amount' => 0,
        ], $this->idem())->assertStatus(422);
        $dead = $this->makeVariant(10.00);
        $dead->update(['is_active' => false]);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $dead->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash_amount' => 10, 'card_amount' => 0,
        ], $this->idem())->assertStatus(404);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash_amount' => 100, 'card_amount' => 0,
            'notes' => str_repeat('ن', 501),
        ], $this->idem())->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 0]],
            'payment_method' => 'cash', 'cash_amount' => 0, 'card_amount' => 0,
        ], $this->idem())->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 10000]],
            'payment_method' => 'cash', 'cash_amount' => 1000000, 'card_amount' => 0,
        ], $this->idem())->assertStatus(422);
    }
}
