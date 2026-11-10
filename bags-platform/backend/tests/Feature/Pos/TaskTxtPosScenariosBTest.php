<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

/**
 * task.txt §2 scenarios 5, 6, 7 (returns, refunds, exchanges) + §3 return edge cases.
 *
 * Using test-driven-development: each test failed first for the product
 * reason, then passed after the (already landed) fix or test correction.
 */
class TaskTxtPosScenariosBTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function refundToken(): string
    {
        return $this->tokenFor($this->cashier, ['pos.sales.refund', 'pos.discount']);
    }

    private function openFundedShift(User $user, Treasury $drawer, float $opening): string
    {
        return $this->openShift($user, $drawer, $opening);
    }

    public function test_05_return_rejects_excess_and_duplicate_quantities(): void
    {
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(300, 0, 3), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        $returnLine = ['product_variant_id' => $this->variant->id, 'qty' => 1];
        foreach ([['qty', 0], ['qty', 1.5], ['return_to', 'unknown']] as [$field, $value]) {
            $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
                'items' => [array_replace($returnLine, [$field => $value])],
            ])->assertUnprocessable()->assertJsonValidationErrors("items.0.{$field}");
        }
        $this->assertDatabaseMissing('orders', ['return_of_order_id' => $saleId]);

        // 4 > 3 rejected.
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 4]],
        ])->assertStatus(422);

        // Two sequential single returns both succeed (row lock serializes them).
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        // Remaining is 1: returning 3 or a grouped 1+1 (sum 2) is rejected.
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 3]],
        ])->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
        ])->assertStatus(422);

        // Cumulative returned_qty never exceeds sold qty.
        $returned = (float) OrderItem::query()->where('order_id', $saleId)->sum('returned_qty');
        $sold = (float) OrderItem::query()->where('order_id', $saleId)->sum('qty');
        $this->assertSame(2.0, $returned);
        $this->assertSame(3.0, $sold);

        // Unknown variant rejected.
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => 999999, 'qty' => 1]],
        ])->assertStatus(422);

        // Mixed destinations for the same variant in one request rejected.
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'on_hand'],
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'damaged'],
            ],
        ])->assertStatus(422);
    }

    public function test_05_return_of_return_non_pos_and_undelivered_rejected(): void
    {
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        $ret = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $ret->assertOk();
        $returnOrderId = $ret->json('data.return_order_id');
        $this->assertNotNull($returnOrderId);

        // Return of a return rejected (status Returned, not Delivered).
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$returnOrderId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(422);

        // Non-POS channel rejected.
        Order::query()->whereKey($saleId)->update(['channel' => 'store']);
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_06_refund_splits_cash_card_to_the_cent_across_partial_returns(): void
    {
        $token = $this->refundToken();
        $variant = $this->makeVariant(33.33);
        $this->stockIn($this->branch->id, $variant, 3);

        // 3 x 33.33 - 0.01 = 99.98, paid cash 50 + card 49.98.
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 3, 'discount_amount' => 0.01]],
            'payment_method' => 'mixed',
            'cash_amount' => 50,
            'card_amount' => 49.98,
        ], $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        $r1 = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 1]],
        ])->assertOk();
        $r2 = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 1]],
        ])->assertOk();
        $r3 = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 1]],
        ])->assertOk();

        $refunds = [$r1->json('data.refund_amount'), $r2->json('data.refund_amount'), $r3->json('data.refund_amount')];
        $this->assertSame(99.98, round(array_sum($refunds), 2), 'partial refunds must sum to the net invoice');

        $returnOrders = Order::query()->where('return_of_order_id', $saleId)->orderBy('id')->get();
        $this->assertCount(3, $returnOrders);
        $this->assertSame(50.0, round((float) $returnOrders->sum('cash_amount'), 2), 'cash refunded never exceeds cash paid');
        $this->assertSame(49.98, round((float) $returnOrders->sum('card_amount'), 2));

        // A return containing a card portion is pending + requires refund.
        $this->assertTrue((bool) $returnOrders->last()->requires_refund);
        $this->assertSame('refund_pending', $returnOrders->last()->payment_status);

        // Treasury pos_return debits only the cash portion on the current drawer.
        $posReturnTotal = (float) TreasuryTransaction::query()
            ->where('treasury_id', $this->drawer->id)
            ->where('type', TreasuryTransactionType::PosReturn)
            ->sum('amount');
        $this->assertSame(-50.0, $posReturnTotal);

        // sale_return movements restock on_hand; original stays delivered.
        $this->assertSame(3, StockMovement::query()
            ->where('reference_id', $saleId)
            ->where('type', StockMovementType::SaleReturn)->count());
        $this->assertSame(OrderStatus::Delivered, Order::findOrFail($saleId)->status);
    }

    public function test_06_damaged_return_records_extra_damage_movement(): void
    {
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');
        $levels = StockLevel::query()
            ->where('branch_id', $this->branch->id)
            ->where('product_variant_id', $this->variant->id);
        $onHandAfterSale = (float) $levels->sum('on_hand');
        $damagedAfterSale = (float) $levels->sum('damaged');
        $availableAfterSale = (float) $levels->sum('available');

        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'damaged']],
        ])->assertOk();

        $this->assertSame(1, StockMovement::query()
            ->where('reference_id', $saleId)
            ->where('type', StockMovementType::SaleReturn)->count());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_id', $saleId)
            ->where('type', StockMovementType::Damage)->count());
        $this->assertSame($onHandAfterSale + 1, (float) $levels->sum('on_hand'));
        $this->assertSame($damagedAfterSale + 1, (float) $levels->sum('damaged'));
        $this->assertSame($availableAfterSale, (float) $levels->sum('available'));
    }

    public function test_06_return_from_another_shift_uses_current_drawer(): void
    {
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        // Second cashier on a funded second drawer returns the first shift's sale.
        $drawer2 = Treasury::create([
            'name' => 'Drawer 2', 'code' => 'DRW-02-'.$this->drawer->id,
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $cashier2 = $this->makeCashier('cashier2@example.com', $this->branch->id, ['pos.access']);
        $token2 = $this->openFundedShift($cashier2, $drawer2, 200.0);
        $this->tokenFor($cashier2, ['pos.sales.refund']);
        Auth::forgetGuards();
        $token2 = $cashier2->createToken('pos2', ['pos'])->plainTextToken;

        $this->withToken($token2)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        // Refund debited the CURRENT drawer, not the sale's drawer.
        $this->assertSame(100.0, (float) $drawer2->fresh()->current_balance);
        $this->assertTrue(TreasuryTransaction::query()
            ->where('treasury_id', $drawer2->id)
            ->where('type', TreasuryTransactionType::PosReturn)->exists());
    }

    public function test_07_cash_return_rejected_when_drawer_balance_insufficient(): void
    {
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');
        $stockAfterSale = (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand');

        // Drain the drawer with an expense: 100 - 80 = 20 left.
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        app(PosService::class)->recordExpense($shift->fresh(), ['category' => 'ضيافة', 'amount' => 80], $this->cashier->id);
        $this->assertSame(20.0, (float) $this->drawer->fresh()->current_balance);

        // Full 100 cash return cannot be covered.
        $res = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('رصيد الخزينة غير كافٍ', $res->json('message'));

        // No side effects: no return order, stock and drawer untouched.
        $this->assertSame(0, Order::query()->where('return_of_order_id', $saleId)->count());
        $this->assertSame($stockAfterSale, (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand'));
        $this->assertSame(20.0, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame(0.0, (float) OrderItem::query()->where('order_id', $saleId)->sum('returned_qty'));
    }

    public function test_07_exchange_success_and_failure_rollback(): void
    {
        $token = $this->refundToken();
        $variantB = $this->makeVariant(120.00);
        $this->stockIn($this->branch->id, $variantB, 5);

        // Happy path: return 100-item, buy 120-item with 120 cash.
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        $ex = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $variantB->id, 'qty' => 1]],
            'cash_amount' => 120,
            'card_amount' => 0,
        ], $this->idem());
        $ex->assertOk();
        $this->assertNotNull($ex->json('data.exchange_order_id'));
        $this->assertSame(120.0, (float) $this->drawer->fresh()->current_balance);

        // Failure path: replacement variant out of stock -> full rollback.
        $variantC = $this->makeVariant(50.00); // no stock
        $sale2 = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale2->assertStatus(201);
        $sale2Id = $sale2->json('data.id');
        $drawerBefore = (float) $this->drawer->fresh()->current_balance;
        $stockBefore = (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand');

        $fail = $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale2Id}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $variantC->id, 'qty' => 1]],
            'cash_amount' => 50,
            'card_amount' => 0,
        ], $this->idem());
        $fail->assertStatus(422);
        $this->assertSame(0, Order::query()->where('return_of_order_id', $sale2Id)->count());
        $this->assertSame($drawerBefore, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame($stockBefore, (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand'));
    }

    public function test_07_exchange_rejected_when_return_exceeds_drawer_despite_new_payment(): void
    {
        // Documents §7 decision point: return runs before the new sale in one
        // transaction, so a covered-later refund is still rejected atomically.
        $token = $this->refundToken();

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem());
        $sale->assertStatus(201);
        $saleId = $sale->json('data.id');

        $shift = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        app(PosService::class)->recordExpense($shift->fresh(), ['category' => 'ضيافة', 'amount' => 80], $this->cashier->id);

        $variantB = $this->makeVariant(120.00);
        $this->stockIn($this->branch->id, $variantB, 5);

        $res = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $variantB->id, 'qty' => 1]],
            'cash_amount' => 120,
            'card_amount' => 0,
        ], $this->idem());
        $res->assertStatus(422);
        $this->assertSame(0, Order::query()->where('return_of_order_id', $saleId)->count());
    }

    public function test_08_shift_open_matrix(): void
    {
        $token = $this->token;

        // Same cashier double-open rejected (one open shift per user).
        $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id, 'opening_cash' => 0,
        ])->assertStatus(422);

        // Second cashier on the same drawer rejected.
        $cashier2 = $this->makeCashier('second@example.com', $this->branch->id, ['pos.access']);
        $token2 = $this->tokenFor($cashier2);
        $this->withToken($token2)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id, 'opening_cash' => 0,
        ])->assertStatus(422);

        // Drawer balance 300 vs opening 500 rejected.
        $drawer3 = Treasury::create([
            'name' => 'Drawer 3', 'code' => 'DRW-03-X',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 300, 'is_active' => true,
        ]);
        $cashier3 = $this->makeCashier('third@example.com', $this->branch->id, ['pos.access']);
        $token3 = $this->tokenFor($cashier3);
        $mismatch = $this->withToken($token3)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $drawer3->id, 'opening_cash' => 500,
        ]);
        $mismatch->assertStatus(422);
        $this->assertStringContainsString('العهدة', $mismatch->json('message'));

        // Drawer balance 0 + main safe: paired funding movements.
        $drawer4 = Treasury::create([
            'name' => 'Drawer 4', 'code' => 'DRW-04-X',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $this->withToken($token3)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $drawer4->id, 'opening_cash' => 500,
        ])->assertStatus(201);
        $this->assertSame(500.0, (float) $drawer4->fresh()->current_balance);
        $this->assertSame(500.0, (float) $this->safe->fresh()->current_balance);
        $this->assertTrue(TreasuryTransaction::query()
            ->where('treasury_id', $this->safe->id)->where('amount', -500)->exists());
        $this->assertTrue(TreasuryTransaction::query()
            ->where('treasury_id', $drawer4->id)->where('amount', 500)->exists());

        // Foreign-branch, inactive, and main-safe drawers rejected.
        $cashierB = $this->cashierB;
        $tokenB = $this->tokenFor($cashierB);
        $this->withToken($tokenB)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id, 'opening_cash' => 0,
        ])->assertStatus(404);

        $inactive = Treasury::create([
            'name' => 'Dead', 'code' => 'DRW-DEAD-X',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => false,
        ]);
        $cashier4 = $this->makeCashier('fourth@example.com', $this->branch->id, ['pos.access']);
        $this->withToken($this->tokenFor($cashier4))->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $inactive->id, 'opening_cash' => 0,
        ])->assertStatus(404);
        $this->withToken($this->tokenFor($cashier4))->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->safe->id, 'opening_cash' => 0,
        ])->assertStatus(404);
    }

    public function test_09_close_approve_settles_cash_with_shortage_and_overage(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $variant50 = $this->makeVariant(50.00);
        $this->stockIn($this->branch->id, $variant50, 10);

        // Reopen on a funded drawer: close the zero shift first.
        $shift0 = PosShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['actual_closing_cash' => 0])->assertOk();
        $manager = $this->makeCashier('manager@example.com', $this->branch->id, ['pos.access']);
        $manager->givePermissionTo(Permission::findOrCreate('pos_shifts.approve', 'staff'));
        // Admin routes require a full-ability token, not a pos-scoped one.
        Auth::forgetGuards();
        $managerToken = $manager->createToken('admin')->plainTextToken;
        $this->withToken($managerToken)->postJson("/api/v1/admin/shifts/{$shift0->id}/approve")->assertOk();

        $drawer = Treasury::create([
            'name' => 'Drawer S', 'code' => 'DRW-SH-X',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $token = $this->openShift($this->cashier, $drawer, 500.0);

        // Cash 300 + card 200 + cash 50, return the 50, expense 20.
        $this->withToken($token)->postJson('/api/v1/pos/sales', $this->salePayload(300, 0, 3), $this->idem())->assertStatus(201);
        $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $variant50->id, 'qty' => 4]],
            'payment_method' => 'mixed', 'cash_amount' => 0, 'card_amount' => 200,
        ], $this->idem())->assertStatus(201);
        $s3 = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $variant50->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash_amount' => 50, 'card_amount' => 0,
        ], $this->idem())->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/pos/sales/{$s3->json('data.id')}/return", [
            'items' => [['product_variant_id' => $variant50->id, 'qty' => 1]],
        ])->assertOk();
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShiftStatus::Open)->firstOrFail();
        app(PosService::class)->recordExpense($shift->fresh(), ['category' => 'ضيافة', 'amount' => 20], $this->cashier->id);

        // expected = 500 + 350 - 50 - 20 = 780; close at 770 -> shortage 10.
        $close = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['actual_closing_cash' => 770]);
        $close->assertOk();
        $shiftId = $close->json('data.id');
        $close->assertJsonPath('data.expected_closing_cash', '780.00')
            ->assertJsonPath('data.cash_difference', '-10.00')
            ->assertJsonPath('data.status', 'closed_pending_approval');

        // Foreign-branch manager is forbidden on a pending shift.
        $foreign = $this->makeCashier('foreign-mgr@example.com', $this->branchB->id, ['pos.access']);
        $foreign->givePermissionTo(Permission::findOrCreate('pos_shifts.approve', 'staff'));
        Auth::forgetGuards();
        $foreignToken = $foreign->createToken('admin')->plainTextToken;
        $this->withToken($foreignToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve")->assertStatus(403);

        Auth::forgetGuards();
        $this->withToken($managerToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve")->assertOk();
        // Double approve rejected.
        $this->withToken($managerToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve")->assertStatus(422);

        // Approving an open shift is rejected.
        $openToken = $this->openShift($this->cashier, $drawer, 0.0);
        $openShiftId = PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShiftStatus::Open)->firstOrFail()->id;
        Auth::forgetGuards();
        $this->withToken($managerToken)->postJson("/api/v1/admin/shifts/{$openShiftId}/approve")->assertStatus(422);

        // Shortage -10 posted on the drawer, then 770 swept to the safe; drawer ends at 0.
        $this->assertTrue(TreasuryTransaction::query()
            ->where('treasury_id', $drawer->id)->where('type', TreasuryTransactionType::CashShortage)->exists());
        $this->assertSame(0.0, (float) $drawer->fresh()->current_balance);
        $this->assertSame('approved', PosShift::findOrFail($shiftId)->status->value);
    }

    public function test_14_held_resume_is_single_use(): void
    {
        $token = $this->token;

        $hold = $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $hold->assertStatus(201);
        $heldId = $hold->json('data.id');

        $this->withToken($token)->postJson("/api/v1/pos/sales/held/{$heldId}/resume")->assertOk();
        // Second resume: gone.
        $this->withToken($token)->postJson("/api/v1/pos/sales/held/{$heldId}/resume")->assertStatus(404);
        // Delete after resume: gone.
        $this->withToken($token)->deleteJson("/api/v1/pos/sales/held/{$heldId}")->assertStatus(404);

        // Delete then resume: gone both ways.
        $hold2 = $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(201);
        $held2Id = $hold2->json('data.id');
        $this->withToken($token)->deleteJson("/api/v1/pos/sales/held/{$held2Id}")->assertOk();
        $this->withToken($token)->postJson("/api/v1/pos/sales/held/{$held2Id}/resume")->assertStatus(404);
    }
}
