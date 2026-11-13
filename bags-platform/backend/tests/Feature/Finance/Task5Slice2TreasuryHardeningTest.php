<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Pos\ExpenseService;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task5Slice2TreasuryHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeTreasury(Branch $branch, string $type = 'main_safe', array $extra = []): Treasury
    {
        $balance = $extra['current_balance'] ?? null;
        unset($extra['current_balance']);

        $t = Treasury::create([
            'name' => 'خزينة '.$type,
            'code' => 'TR-'.uniqid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::from($type),
            'is_active' => true,
            ...$extra,
        ]);

        if ($balance !== null) {
            $t->forceFill(['current_balance' => $balance])->save();
        }

        return $t->fresh();
    }

    public function test_f08b_deactivation_allows_zero_balance_account_with_history(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = $this->makeTreasury($branch);

        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '100.00',
            'created_by' => $actor->id,
        ]);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::Expense,
            'amount' => '-100.00',
            'created_by' => $actor->id,
        ]);

        $treasury->refresh();
        $this->assertSame('0.00', (string) $treasury->current_balance);
        $this->assertSame([], app(FinanceWriteGuards::class)->deactivationBlockers($treasury));

        $stopped = app(TreasuryService::class)->updateAccount($treasury, ['is_active' => false], $actor);
        $this->assertFalse((bool) $stopped->is_active);

        $stopped->forceFill(['is_active' => true, 'current_balance' => '10.00'])->save();
        $blocked = app(FinanceWriteGuards::class)->deactivationBlockers($stopped->fresh());
        $this->assertContains('الرصيد مش صفر', $blocked);

        try {
            app(TreasuryService::class)->updateAccount($stopped->fresh(), ['is_active' => false], $actor);
            $this->fail('Expected a non-zero balance to block deactivation.');
        } catch (BusinessException) {
        }
    }

    public function test_f09b_duplicate_treasury_code_maps_to_409_instead_of_500(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $service = app(TreasuryService::class);

        $service->createAccount([
            'name' => 'خزينة أولى',
            'code' => 'DUP-CODE-1',
            'branch_id' => $branch->id,
            'type' => 'main_safe',
        ], $actor);

        try {
            $service->createAccount([
                'name' => 'خزينة ثانية',
                'code' => 'DUP-CODE-1',
                'branch_id' => $branch->id,
                'type' => 'main_safe',
            ], $actor);
            $this->fail('Expected duplicate code creation to be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        $second = $this->makeTreasury($branch);
        try {
            $service->updateAccount($second, ['code' => 'DUP-CODE-1'], $actor);
            $this->fail('Expected duplicate code update to be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_f09_treasury_current_balance_is_not_mass_assignable(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);

        $direct = Treasury::create([
            'name' => 'خزينة محمية',
            'code' => 'MASS-1',
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'current_balance' => '999999.00',
            'is_active' => true,
        ])->fresh();
        $this->assertSame('0.00', (string) $direct->current_balance);

        $created = app(TreasuryService::class)->createAccount([
            'name' => 'خزينة خدمة',
            'code' => 'MASS-2',
            'branch_id' => $branch->id,
            'type' => 'main_safe',
            'current_balance' => '5000.00',
            'is_active' => true,
        ], $actor);
        $this->assertSame('0.00', (string) $created->current_balance);

        $updated = app(TreasuryService::class)->updateAccount($created, [
            'name' => 'خزينة محدثة',
            'current_balance' => '7777.00',
        ], $actor);
        $this->assertSame('خزينة محدثة', $updated->name);
        $this->assertSame('0.00', (string) $updated->current_balance);
    }

    public function test_f08_treasury_transfers_block_move_deactivation_and_deletion(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create();
        $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('inventory.all_branches', 'staff'));
        $from = $this->makeTreasury($branchA);
        $to = $this->makeTreasury($branchA);

        $pending = TreasuryTransfer::create([
            'transfer_number' => 'TT-PEND-1',
            'from_treasury_id' => $from->id,
            'to_treasury_id' => $to->id,
            'amount' => '100.00',
            'status' => TreasuryTransferStatus::Pending,
            'created_by' => $actor->id,
        ]);

        // Pending incoming transfer must block deactivation & branch move & guards
        $blockers = app(FinanceWriteGuards::class)->deactivationBlockers($to);
        $this->assertNotEmpty($blockers);

        try {
            app(TreasuryService::class)->updateAccount($to, ['is_active' => false], $actor);
            $this->fail('Expected pending incoming transfer to block deactivation.');
        } catch (BusinessException) {
        }

        try {
            app(TreasuryService::class)->updateAccount($to, ['branch_id' => $branchB->id], $actor);
            $this->fail('Expected existing transfer to block moving treasury branch.');
        } catch (BusinessException) {
        }

        $pending->update(['status' => TreasuryTransferStatus::Rejected, 'rejected_by' => $actor->id]);

        // Even after rejection, historical transfer references still block branch change and deletion
        try {
            app(TreasuryService::class)->updateAccount($to, ['branch_id' => $branchB->id], $actor);
            $this->fail('Expected historical transfer reference to block branch change.');
        } catch (BusinessException) {
        }

        try {
            app(TreasuryService::class)->deleteAccount($to, $actor);
            $this->fail('Expected historical transfer reference to block deleting treasury.');
        } catch (BusinessException) {
        }
    }

    public function test_f10_expense_create_rechecks_is_active_under_lock_before_burning_sequence(): void
    {
        $this->seed(SettingSeeder::class);
        app(SettingService::class)->clearCache();

        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = $this->makeTreasury($branch, 'main_safe', ['current_balance' => '500.00']);

        $beforeSeq = DB::selectOne('SELECT last_value, is_called FROM document_expense_number_seq');
        $beforeNextNumber = (int) $beforeSeq->last_value + ($beforeSeq->is_called ? 1 : 0);

        // Deactivate treasury cleanly right after unlocked validation passes and before tx lock acquires.
        $racingIdempotency = new class($treasury->id) extends OperationIdempotencyService
        {
            public function __construct(private int $treasuryId) {}

            public function run(string $operation, Model $actor, ?string $key, array $payload, callable $execute): Model
            {
                DB::table('treasuries')->where('id', $this->treasuryId)->update(['is_active' => false]);

                return DB::transaction(fn (): Model => $execute());
            }
        };
        $this->app->instance(OperationIdempotencyService::class, $racingIdempotency);

        try {
            app(ExpenseService::class)->create([
                'branch_id' => $branch->id,
                'treasury_id' => $treasury->id,
                'category' => 'ضيافة',
                'amount' => '50.00',
                'expense_date' => now()->toDateString(),
            ], $actor, 'exp-race-key-1');
            $this->fail('Expected inactive treasury under lock to reject expense.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('الخزينة', $e->getMessage());
        }

        $afterSeq = DB::selectOne('SELECT last_value, is_called FROM document_expense_number_seq');
        $afterNextNumber = (int) $afterSeq->last_value + ($afterSeq->is_called ? 1 : 0);

        $this->assertSame($beforeNextNumber, $afterNextNumber, 'Expense number sequence must not burn when locked treasury is inactive.');
    }

    public function test_f10_shift_settlement_rechecks_main_safe_active_state_under_lock(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $drawer = $this->makeTreasury($branch, 'pos_drawer', ['current_balance' => '150.00']);
        $safe = $this->makeTreasury($branch, 'main_safe', ['current_balance' => '0.00']);

        $shift = PosShift::create([
            'shift_number' => 'SH-LOCK-1',
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'treasury_id' => $drawer->id,
            'status' => PosShiftStatus::ClosedPendingApproval,
            'opened_at' => now()->subHours(2),
            'closed_at' => now(),
            'opening_cash' => '100.00',
            'actual_closing_cash' => '150.00',
        ]);

        // Flip mainSafe inactive immediately after unlocked candidate selection in settleShiftToMainSafe,
        // before lockTreasuries loads it FOR UPDATE.
        $flipped = false;
        Treasury::retrieved(function (Treasury $model) use ($safe, &$flipped): void {
            if (! $flipped && $model->id === $safe->id) {
                $flipped = true;
                DB::table('treasuries')->where('id', $safe->id)->update(['is_active' => false]);
            }
        });

        try {
            app(TreasuryService::class)->settleShiftToMainSafe($shift, $actor->id);
            $this->fail('Expected settlement to reject deactivated main safe under lock.');
        } catch (BusinessException) {
        } finally {
            Treasury::flushEventListeners();
        }

        $this->assertSame('0.00', (string) $safe->fresh()->current_balance);
        $this->assertSame('150.00', (string) $drawer->fresh()->current_balance);
    }

    public function test_f12_malformed_or_overflow_amounts_throw_business_exception(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $treasuryService = app(TreasuryService::class);

        $before = Treasury::query()->count();
        foreach (['1e5', 'not-a-number', '-50.00'] as $badOpening) {
            try {
                $treasuryService->createAccount([
                    'name' => 'خزينة اختبار',
                    'code' => 'BAD-'.uniqid(),
                    'branch_id' => $branch->id,
                    'type' => 'main_safe',
                    'is_active' => true,
                ], $actor, $badOpening);
                $this->fail("Expected BusinessException for opening balance '{$badOpening}'.");
            } catch (BusinessException) {
            }
        }
        $this->assertSame($before, Treasury::query()->count());

        $bank = $this->makeTreasury($branch, 'bank_account');
        try {
            $treasuryService->record([
                'treasury_id' => $bank->id,
                'type' => TreasuryTransactionType::CustomerReceipt,
                'amount' => '1000000000000.00',
                'created_by' => $actor->id,
            ]);
            $this->fail('Expected numeric(14,2) overflow guard to throw BusinessException.');
        } catch (BusinessException) {
        }

        $customer = Customer::factory()->create();
        try {
            app(WalletAdjustmentService::class)->adjust([
                'customer_id' => $customer->id,
                'kind' => 'adjustment',
                'amount' => '99999999999.00',
                'direction' => 'credit',
                'reason' => 'مبلغ فوق سعة الجدول',
                'source' => 'admin',
            ], $actor, 'wallet-overflow-1');
            $this->fail('Expected adjustment amount overflow guard to throw BusinessException.');
        } catch (BusinessException) {
        }
    }

    public function test_f21_expense_void_validates_non_empty_reason_and_clamps_length(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = $this->makeTreasury($branch, 'main_safe', ['current_balance' => '500.00']);

        $expense = Expense::create([
            'expense_number' => 'EXP-VOID-1',
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'amount' => '40.00',
            'category' => 'نثريات',
            'expense_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);

        try {
            app(ExpenseService::class)->void($expense, '   ', $actor);
            $this->fail('Expected blank void reason to be rejected with BusinessException.');
        } catch (BusinessException) {
        }
        $this->assertNull($expense->fresh()->voided_at);

        $longReason = str_repeat('س', 650);
        $voided = app(ExpenseService::class)->void($expense, $longReason, $actor);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame(500, mb_strlen((string) $voided->void_reason));
    }
}
