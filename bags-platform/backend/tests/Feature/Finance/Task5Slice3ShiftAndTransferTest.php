<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Pos\ExpenseService;
use App\Services\Pos\PosService;
use App\Services\Pos\TreasuryService;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task5Slice3ShiftAndTransferTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, FinancialPermissionSeeder::class]);
    }

    private function userWithPermissions(?int $branchId, array $permissions): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $user;
    }

    public function test_f03_zero_cash_shift_with_zero_variance_cannot_settle_twice(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = $this->userWithPermissions($branch->id, ['pos.access']);
        $approver = $this->userWithPermissions($branch->id, ['pos_shifts.approve']);

        Treasury::create([
            'name' => 'Main Safe',
            'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);
        $drawer = Treasury::create([
            'name' => 'Drawer 1',
            'code' => 'DRW-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer,
            'is_active' => true,
        ]);

        $pos = app(PosService::class);
        $shift = $pos->openShift($cashier, $branch->id, $drawer->id, 0.0);
        $closed = $pos->closeShift($shift, 0.0, null);
        $approved = $pos->approveShift($closed, $approver);

        $this->assertSame(PosShiftStatus::Approved, $approved->status);
        $this->assertNotNull($approved->settled_at);

        $this->expectException(BusinessException::class);
        app(TreasuryService::class)->settleShiftToMainSafe($approved, $approver->id);
    }

    public function test_f03_negative_actual_closing_cash_is_rejected_with_business_exception(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = $this->userWithPermissions($branch->id, ['pos.access']);
        $approver = $this->userWithPermissions($branch->id, ['pos_shifts.approve']);

        Treasury::create([
            'name' => 'Main Safe',
            'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);
        $drawer = Treasury::create([
            'name' => 'Drawer 1',
            'code' => 'DRW-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer,
            'is_active' => true,
        ]);

        $shift = PosShift::create([
            'shift_number' => 'SH-NEG-1',
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'treasury_id' => $drawer->id,
            'status' => PosShiftStatus::ClosedPendingApproval,
            'opened_at' => now()->subHours(2),
            'closed_at' => now(),
            'opening_cash' => '0.00',
            'expected_closing_cash' => '0.00',
            'actual_closing_cash' => '-10.00',
            'cash_difference' => '-10.00',
        ]);

        try {
            app(TreasuryService::class)->settleShiftToMainSafe($shift, $approver->id);
            $this->fail('Negative actual_closing_cash must be rejected with BusinessException.');
        } catch (BusinessException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertNull($shift->fresh()->settled_at);
        $this->assertSame('0.00', $drawer->fresh()->current_balance);
    }

    public function test_f13_restricted_branch_manager_only_sees_transfers_fully_inside_the_branch(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        $centralAdmin = $this->userWithPermissions(null, ['inventory.all_branches', 'treasury_transfers.view', 'treasury_transfers.create', 'treasury_transfers.approve']);
        $branchBManager = $this->userWithPermissions($branchB->id, ['treasury_transfers.view', 'treasury_transfers.approve']);

        $centralSafe = tap(Treasury::create([
            'name' => 'Central Safe',
            'code' => 'CENT-'.Str::uuid(),
            'branch_id' => null,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '5000.00'])->save());

        $safeA = tap(Treasury::create([
            'name' => 'Safe A',
            'code' => 'SA-'.Str::uuid(),
            'branch_id' => $branchA->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '5000.00'])->save());

        $safeB = tap(Treasury::create([
            'name' => 'Safe B',
            'code' => 'SB-'.Str::uuid(),
            'branch_id' => $branchB->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '1000.00'])->save());

        $safeB2 = tap(Treasury::create([
            'name' => 'Safe B2',
            'code' => 'SB2-'.Str::uuid(),
            'branch_id' => $branchB->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '0.00'])->save());

        $treasury = app(TreasuryService::class);
        $centralToB = $treasury->createTransfer(['from_treasury_id' => $centralSafe->id, 'to_treasury_id' => $safeB->id, 'amount' => '300.00'], $centralAdmin->id);
        $aToB = $treasury->createTransfer(['from_treasury_id' => $safeA->id, 'to_treasury_id' => $safeB->id, 'amount' => '200.00'], $centralAdmin->id);
        $centralToA = $treasury->createTransfer(['from_treasury_id' => $centralSafe->id, 'to_treasury_id' => $safeA->id, 'amount' => '150.00'], $centralAdmin->id);
        $bToB = $treasury->createTransfer(['from_treasury_id' => $safeB->id, 'to_treasury_id' => $safeB2->id, 'amount' => '100.00'], $centralAdmin->id);

        $token = $branchBManager->createToken('branch-b')->plainTextToken;

        $indexIds = collect(
            $this->withToken($token)->getJson('/api/v1/admin/treasury-transfers')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$bToB->id], $indexIds);

        Auth::forgetGuards();
        $this->withToken($token)->getJson("/api/v1/admin/treasury-transfers/{$bToB->id}")->assertOk();
        foreach ([$centralToB, $aToB, $centralToA] as $hidden) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson("/api/v1/admin/treasury-transfers/{$hidden->id}")->assertForbidden();
        }

        // Mutations across foreign/central treasury boundary remain forbidden.
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/treasury-transfers/{$centralToB->id}/approve")->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/treasury-transfers/{$centralToB->id}/reject", ['reason' => 'غير مخول'])->assertForbidden();
    }

    public function test_f13_restricted_branch_manager_requesting_foreign_branch_shifts_gets_403(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $branchBManager = $this->userWithPermissions($branchB->id, ['pos_shifts.view']);
        $token = $branchBManager->createToken('shifts-b')->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/admin/shifts?branch_id={$branchA->id}")->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->getJson("/api/v1/admin/shifts?branch_id={$branchB->id}")->assertOk();
    }

    public function test_f14_expense_void_authorizes_locked_row_and_replays_with_idempotency_key(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $adminA = $this->userWithPermissions($branchA->id, ['expenses.create', 'expenses.delete']);
        $central = $this->userWithPermissions(null, ['inventory.all_branches', 'expenses.create', 'expenses.delete']);

        $safeA = tap(Treasury::create([
            'name' => 'Safe A',
            'code' => 'EXPA-'.Str::uuid(),
            'branch_id' => $branchA->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '1000.00'])->save());

        $expense = app(ExpenseService::class)->create([
            'branch_id' => $branchA->id,
            'treasury_id' => $safeA->id,
            'category' => 'ضيافة',
            'amount' => '100.00',
            'expense_date' => now()->toDateString(),
        ], $adminA, 'exp-setup-1');

        $token = $adminA->createToken('exp-a')->plainTextToken;
        $headers = ['Idempotency-Key' => 'void-exp-retry-1'];

        $first = $this->withToken($token)
            ->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => 'مكرر'], $headers)
            ->assertOk();
        Auth::forgetGuards();
        $replay = $this->withToken($token)
            ->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => 'مكرر'], $headers)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame('1000.00', $safeA->fresh()->current_balance);
        $this->assertSame(1, TreasuryTransaction::query()
            ->where('reference_type', Expense::class)
            ->where('reference_id', $expense->id)
            ->where('type', TreasuryTransactionType::ExpenseReversal->value)
            ->count());

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => 'سبب آخر'], $headers)
            ->assertConflict();

        // Service-level authorization uses the locked DB row, not stale in-memory attributes.
        $expense2 = app(ExpenseService::class)->create([
            'branch_id' => $branchA->id,
            'treasury_id' => $safeA->id,
            'category' => 'نقل',
            'amount' => '50.00',
            'expense_date' => now()->toDateString(),
        ], $central, 'exp-setup-2');
        $staleExpense = Expense::findOrFail($expense2->id);
        DB::table('expenses')->where('id', $expense2->id)->update(['branch_id' => $branchB->id]);

        $this->expectException(AuthorizationException::class);
        app(ExpenseService::class)->void($staleExpense, 'إلغاء بعد نقل الفرع', $adminA);
    }

    public function test_f14_transfer_approve_and_shift_approve_support_idempotency_key_replay(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $creator = $this->userWithPermissions($branch->id, ['treasury_transfers.create', 'pos.access']);
        $approver = $this->userWithPermissions($branch->id, ['treasury_transfers.approve', 'pos_shifts.approve']);

        $safe = tap(Treasury::create([
            'name' => 'Main Safe',
            'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => '2000.00'])->save());
        $bank = Treasury::create([
            'name' => 'Bank',
            'code' => 'BNK-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::BankAccount,
            'is_active' => true,
        ]);

        $transfer = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $safe->id,
            'to_treasury_id' => $bank->id,
            'amount' => '500.00',
        ], $creator->id);

        $token = $approver->createToken('approver')->plainTextToken;
        $trHeaders = ['Idempotency-Key' => 'approve-transfer-retry-1'];

        $tr1 = $this->withToken($token)
            ->postJson("/api/v1/admin/treasury-transfers/{$transfer->id}/approve", [], $trHeaders)
            ->assertOk();
        Auth::forgetGuards();
        $tr2 = $this->withToken($token)
            ->postJson("/api/v1/admin/treasury-transfers/{$transfer->id}/approve", [], $trHeaders)
            ->assertOk();

        $this->assertSame(TreasuryTransferStatus::Approved->value, $tr1->json('data.status'));
        $this->assertSame($tr1->json('data.id'), $tr2->json('data.id'));
        $this->assertSame('1500.00', $safe->fresh()->current_balance);
        $this->assertSame('500.00', $bank->fresh()->current_balance);

        $drawer = Treasury::create([
            'name' => 'Drawer 1',
            'code' => 'DRW-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer,
            'is_active' => true,
        ]);
        $pos = app(PosService::class);
        $shift = $pos->openShift($creator, $branch->id, $drawer->id, 100.0);
        $closed = $pos->closeShift($shift, 100.0, null);

        $shHeaders = ['Idempotency-Key' => 'approve-shift-retry-1'];
        Auth::forgetGuards();
        $sh1 = $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$closed->id}/approve", [], $shHeaders)
            ->assertOk();
        Auth::forgetGuards();
        $sh2 = $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$closed->id}/approve", [], $shHeaders)
            ->assertOk();

        $this->assertSame(PosShiftStatus::Approved->value, $sh1->json('data.status'));
        $this->assertSame($sh1->json('data.id'), $sh2->json('data.id'));
        $this->assertSame('1500.00', $safe->fresh()->current_balance);
        $this->assertSame('0.00', $drawer->fresh()->current_balance);
    }

    public function test_create_transfer_reserves_pending_incoming_against_the_balance_ceiling(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $creator = $this->userWithPermissions($branch->id, ['treasury_transfers.create']);
        $from = Treasury::create([
            'name' => 'Source Bank', 'code' => 'SRC-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::BankAccount, 'is_active' => true,
        ]);
        $to = Treasury::create([
            'name' => 'Dest Bank', 'code' => 'DST-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::BankAccount, 'is_active' => true,
        ]);
        $to->forceFill(['current_balance' => '999999999990.00'])->save();

        $service = app(TreasuryService::class);
        $service->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '5.00',
        ], $creator->id);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('يتجاوز الحد المسموح');
        $service->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '10.00',
        ], $creator->id);
    }

    public function test_only_the_creator_can_cancel_a_pending_transfer(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $creator = $this->userWithPermissions($branch->id, ['treasury_transfers.create', 'treasury_transfers.approve']);
        $other = $this->userWithPermissions($branch->id, ['treasury_transfers.create']);
        $from = Treasury::create([
            'name' => 'Source Bank', 'code' => 'SRC-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::BankAccount, 'is_active' => true,
        ]);
        $to = Treasury::create([
            'name' => 'Dest Bank', 'code' => 'DST-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::BankAccount, 'is_active' => true,
        ]);

        $service = app(TreasuryService::class);
        $own = $service->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '25.00',
        ], $creator->id);

        $creatorToken = $creator->createToken('creator')->plainTextToken;
        $this->withToken($creatorToken)
            ->postJson("/api/v1/admin/treasury-transfers/{$own->id}/reject", ['reason' => 'اختبار الرفض'])
            ->assertForbidden();
        $this->assertSame(TreasuryTransferStatus::Pending, $own->fresh()->status);

        $this->withToken($creatorToken)
            ->postJson("/api/v1/admin/treasury-transfers/{$own->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', TreasuryTransferStatus::Rejected->value);
        $this->assertSame($creator->id, (int) $own->fresh()->rejected_by);

        $foreign = $service->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '30.00',
        ], $creator->id);

        Auth::forgetGuards();
        $otherToken = $other->createToken('other')->plainTextToken;
        $this->withToken($otherToken)
            ->postJson("/api/v1/admin/treasury-transfers/{$foreign->id}/cancel")
            ->assertForbidden();
        $this->assertSame(TreasuryTransferStatus::Pending->value, $foreign->fresh()->status->value);
    }
}
