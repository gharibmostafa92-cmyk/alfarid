<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PosExpenseLifecycleTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function asToken(string $token): static
    {
        Auth::forgetGuards();
        app()->forgetScopedInstances();

        return $this->withToken($token);
    }

    private function openFundedShift(float $openingCash = 500.0): array
    {
        $cashier = $this->makeCashier('expense-cashier-'.Str::random(4).'@example.com', $this->branch->id, ['pos.access']);
        $drawer = Treasury::create([
            'name' => 'Funded Drawer',
            'code' => 'DRW-EXP-'.Str::random(6),
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $token = $this->openShift($cashier, $drawer, $openingCash);
        $shift = PosShift::query()->where('user_id', $cashier->id)->where('status', PosShiftStatus::Open)->firstOrFail();

        return [$cashier, $drawer, $shift, $token];
    }

    public function test_cashier_can_list_shift_expenses_without_exposing_drawer_balance(): void
    {
        [, $drawer, $shift, $token] = $this->openFundedShift(500.0);

        // Foreign shift expense on another branch must never appear.
        $foreignCashier = $this->cashierB;
        $foreignToken = $this->openShift($foreignCashier, $this->drawerB, 0.0);
        // Fund drawer B via sale so it can record an expense.
        $this->stockIn($this->branchB->id, $this->variant, 10, $foreignCashier->id);
        $this->asToken($foreignToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())->assertCreated();
        $this->asToken($foreignToken)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 25,
            'category' => 'مصروف فرع آخر',
        ], $this->idem())->assertCreated();

        $this->asToken($token)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 45.50,
            'category' => 'ضيافة',
            'notes' => 'شاي وقهوة',
        ], $this->idem())->assertCreated();

        $list = $this->asToken($token)->getJson('/api/v1/pos/sales/expenses');
        $list->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'ضيافة')
            ->assertJsonPath('data.0.amount', 45.5)
            ->assertJsonPath('data.0.notes', 'شاي وقهوة')
            ->assertJsonPath('data.0.status', 'posted')
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.can_void', true)
            ->assertJsonMissingPath('data.0.current_balance')
            ->assertJsonMissingPath('data.0.expected_closing_cash')
            ->assertJsonMissingPath('expected_closing_cash');

        $this->assertSame('454.50', $drawer->fresh()->current_balance);
        $this->assertSame(45.5, app(PosService::class)->computeShiftTotals($shift)['cash_expenses_total']);
    }

    public function test_editing_category_and_notes_updates_in_place_without_extra_ledger_entries(): void
    {
        [, $drawer, , $token] = $this->openFundedShift(300.0);

        $expenseId = (int) $this->withToken($token)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 60,
            'category' => 'نقل',
            'notes' => 'خطأ إملائي',
        ], $this->idem())->assertCreated()->json('data.id');

        $key = $this->idem('exp-edit-meta');
        $res = $this->withToken($token)->patchJson("/api/v1/pos/sales/expenses/{$expenseId}", [
            'category' => 'نقل بضاعة',
            'notes' => 'توصيل طرد',
        ], $key);
        $res->assertOk()
            ->assertJsonPath('data.id', $expenseId)
            ->assertJsonPath('data.category', 'نقل بضاعة')
            ->assertJsonPath('data.notes', 'توصيل طرد')
            ->assertJsonPath('data.amount', 60);

        // Idempotent replay
        $this->withToken($token)->patchJson("/api/v1/pos/sales/expenses/{$expenseId}", [
            'category' => 'نقل بضاعة',
            'notes' => 'توصيل طرد',
        ], $key)->assertOk()->assertJsonPath('data.id', $expenseId);

        $this->assertSame(1, TreasuryTransaction::query()
            ->where('reference_type', Expense::class)
            ->where('reference_id', $expenseId)
            ->count());
        $this->assertSame('240.00', $drawer->fresh()->current_balance);
    }

    public function test_editing_amount_reverses_old_expense_and_creates_replacement_so_shift_approves_cleanly(): void
    {
        [$cashier, $drawer, $shift, $token] = $this->openFundedShift(500.0);

        // Cashier enters 300.00 instead of 30.00 by mistake.
        $oldId = (int) $this->withToken($token)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 300,
            'category' => 'مواصلات',
            'notes' => 'قيد خاطئ',
        ], $this->idem())->assertCreated()->json('data.id');
        $this->assertSame('200.00', $drawer->fresh()->current_balance);

        // Edit amount to 30.00
        $edit = $this->withToken($token)->patchJson("/api/v1/pos/sales/expenses/{$oldId}", [
            'amount' => 30,
            'category' => 'مواصلات',
            'notes' => 'المبلغ الصحيح',
        ], $this->idem('exp-edit-amount'));
        $edit->assertOk()->assertJsonPath('data.amount', 30);

        $newId = (int) $edit->json('data.id');
        $this->assertNotSame($oldId, $newId);

        $oldExpense = Expense::findOrFail($oldId);
        $this->assertNotNull($oldExpense->voided_at);
        $this->assertSame($cashier->id, $oldExpense->voided_by);

        $this->assertDatabaseHas('treasury_transactions', [
            'reference_type' => Expense::class,
            'reference_id' => $oldId,
            'type' => TreasuryTransactionType::ExpenseReversal->value,
            'amount' => '300.00',
        ]);
        $this->assertDatabaseHas('treasury_transactions', [
            'reference_type' => Expense::class,
            'reference_id' => $newId,
            'type' => TreasuryTransactionType::Expense->value,
            'amount' => '-30.00',
        ]);

        // Drawer must now equal 500 - 30 = 470.00
        $this->assertSame('470.00', $drawer->fresh()->current_balance);
        $totals = app(PosService::class)->computeShiftTotals($shift->fresh());
        $this->assertSame(30.0, $totals['cash_expenses_total']);
        $this->assertSame(470.0, $totals['expected_closing_cash']);

        // Close and supervisor-approve shift with exact 470.00 -> must settle with 0 variance & no 409 mismatch.
        $this->withToken($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 470,
        ], $this->idem())->assertOk();

        $supervisor = $this->makeCashier('supervisor-'.Str::random(4).'@example.com', $this->branch->id, ['pos_shifts.approve']);
        $approved = app(PosService::class)->approveShift($shift->fresh(), $supervisor, 'مراجعة سليمة');
        $this->assertSame(PosShiftStatus::Approved, $approved->status);
        $this->assertSame('0.00', $drawer->fresh()->current_balance);
    }

    public function test_editing_amount_above_drawer_cash_rolls_back_atomically(): void
    {
        [, $drawer, , $token] = $this->openFundedShift(200.0);

        $expenseId = (int) $this->withToken($token)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 50,
            'category' => 'ضيافة',
        ], $this->idem())->assertCreated()->json('data.id');

        // Reversal would restore 50 -> 200, then 250 exceeds 200 -> must roll back BOTH.
        $this->withToken($token)->patchJson("/api/v1/pos/sales/expenses/{$expenseId}", [
            'amount' => 250,
        ], $this->idem())->assertStatus(422);

        $this->assertNull(Expense::findOrFail($expenseId)->voided_at);
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame('150.00', $drawer->fresh()->current_balance);
    }

    public function test_voiding_expense_requires_reason_restores_cash_and_rejects_duplicate_or_foreign(): void
    {
        [, $drawer, , $token] = $this->openFundedShift(300.0);

        $expenseId = (int) $this->withToken($token)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 80,
            'category' => 'تغليف',
        ], $this->idem())->assertCreated()->json('data.id');
        $this->assertSame('220.00', $drawer->fresh()->current_balance);

        // Missing void_reason -> 422
        $this->withToken($token)->postJson("/api/v1/pos/sales/expenses/{$expenseId}/void", [], $this->idem())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('void_reason');

        // Foreign cashier on another shift/branch -> 404
        $foreignToken = $this->openShift($this->cashierB, $this->drawerB, 0.0);
        $this->asToken($foreignToken)->postJson("/api/v1/pos/sales/expenses/{$expenseId}/void", [
            'void_reason' => 'محاولة غير مصرح بها',
        ], $this->idem())->assertNotFound();

        // Legitimate void
        $voidKey = $this->idem('void-exp-1');
        $this->asToken($token)->postJson("/api/v1/pos/sales/expenses/{$expenseId}/void", [
            'void_reason' => 'كتب بالخطأ مرتين',
        ], $voidKey)->assertOk()
            ->assertJsonPath('data.void_reason', 'كتب بالخطأ مرتين')
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_void', false);

        $this->asToken($token)->getJson('/api/v1/pos/sales/expenses')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'voided')
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_void', false);

        // Replay with same key succeeds idempotently
        $this->asToken($token)->postJson("/api/v1/pos/sales/expenses/{$expenseId}/void", [
            'void_reason' => 'كتب بالخطأ مرتين',
        ], $voidKey)->assertOk();

        // New key on already-voided expense -> 422
        $this->asToken($token)->postJson("/api/v1/pos/sales/expenses/{$expenseId}/void", [
            'void_reason' => 'محاولة ثانية',
        ], $this->idem())->assertStatus(422);

        $this->assertSame('300.00', $drawer->fresh()->current_balance);
    }
}
