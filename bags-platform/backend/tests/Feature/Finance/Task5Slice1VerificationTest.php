<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Finance\WalletService;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\OperationIdempotencyService;
use Database\Seeders\FinancialPermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task5Slice1VerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function branchTreasury(Branch $branch, string $type = 'main_safe'): Treasury
    {
        return Treasury::create([
            'name' => 'خزينة اختبار '.$type,
            'code' => 'TR-'.uniqid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::from($type),
            'is_active' => true,
        ]);
    }

    public function test_f01_customer_receipt_reversal_passes_postgres_check_constraint(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = $this->branchTreasury($branch);

        $service = app(TreasuryService::class);
        $service->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::CustomerReceipt,
            'amount' => '300.00',
            'created_by' => $actor->id,
        ]);

        $reversal = $service->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::CustomerReceiptReversal,
            'amount' => '-120.00',
            'created_by' => $actor->id,
        ]);

        $this->assertSame(TreasuryTransactionType::CustomerReceiptReversal, $reversal->type);
        $this->assertSame('-120.00', (string) $reversal->amount);
        $this->assertSame('180.00', (string) $treasury->fresh()->current_balance);
    }

    public function test_f02_treasury_ledger_is_append_only_and_shift_deletes_are_restricted(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $drawer = $this->branchTreasury($branch, 'pos_drawer');

        $shift = PosShift::create([
            'shift_number' => 'SH-'.uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'treasury_id' => $drawer->id,
            'status' => PosShiftStatus::Approved,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'opening_cash' => '100.00',
        ]);

        $tx = app(TreasuryService::class)->record([
            'treasury_id' => $drawer->id,
            'shift_id' => $shift->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '100.00',
            'created_by' => $actor->id,
        ]);

        $truncateTrigger = DB::selectOne(<<<'SQL'
            SELECT pg_get_triggerdef(oid) AS def
            FROM pg_trigger
            WHERE tgrelid = 'treasury_transactions'::regclass
              AND NOT tgisinternal
              AND pg_get_triggerdef(oid) ILIKE '%TRUNCATE%'
            SQL);
        $this->assertNotNull($truncateTrigger, 'treasury_transactions must define a BEFORE TRUNCATE immutability trigger.');

        foreach (['update', 'delete', 'truncate'] as $op) {
            try {
                DB::transaction(function () use ($op, $tx): void {
                    match ($op) {
                        'update' => DB::table('treasury_transactions')->where('id', $tx->id)->update(['amount' => '200.00']),
                        'delete' => DB::table('treasury_transactions')->where('id', $tx->id)->delete(),
                        'truncate' => DB::statement('TRUNCATE TABLE treasury_transactions'),
                    };
                });
                $this->fail("Expected PostgreSQL trigger to reject {$op} on treasury_transactions.");
            } catch (QueryException $e) {
                $expectedCodes = $op === 'truncate' ? ['P0001', '55006'] : ['P0001'];
                $this->assertContains($e->errorInfo[0], $expectedCodes);
            }
        }

        try {
            DB::transaction(fn () => DB::table('pos_shifts')->where('id', $shift->id)->delete());
            $this->fail('Expected RESTRICT foreign key to block deleting pos_shifts with treasury_transactions.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23001', '23503']);
        }

        $shiftWithExpense = PosShift::create([
            'shift_number' => 'SH-'.uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'treasury_id' => $drawer->id,
            'status' => PosShiftStatus::Approved,
            'opened_at' => now()->subMinutes(30),
            'closed_at' => now(),
            'opening_cash' => '0.00',
        ]);

        Expense::create([
            'expense_number' => 'EXP-'.uniqid(),
            'branch_id' => $branch->id,
            'treasury_id' => $drawer->id,
            'shift_id' => $shiftWithExpense->id,
            'amount' => '25.00',
            'category' => 'نثريات',
            'expense_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);

        try {
            DB::transaction(fn () => DB::table('pos_shifts')->where('id', $shiftWithExpense->id)->delete());
            $this->fail('Expected RESTRICT foreign key to block deleting pos_shifts with expenses.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23001', '23503']);
        }
    }

    public function test_f19_reconcile_command_outputs_intact_arabic_messages(): void
    {
        $exit = Artisan::call('finance:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('المطابقة', $output);
        $this->assertStringNotContainsString("' .'", $output);
    }

    public function test_f18_financial_permission_seeder_seeds_pos_shift_permissions(): void
    {
        $this->seed(FinancialPermissionSeeder::class);

        foreach (['pos_shifts.view', 'pos_shifts.approve', 'pos_shifts.force_close'] as $permission) {
            $this->assertNotNull(
                Permission::query()->where('name', $permission)->where('guard_name', 'staff')->first(),
                "Permission {$permission} must be seeded by FinancialPermissionSeeder."
            );
        }
    }

    public function test_f04_wallet_adjustment_service_wraps_mutation_in_its_own_transaction(): void
    {
        $customer = Customer::factory()->create(['wallet_balance' => '10.00']);
        $actor = User::factory()->create();

        $passthroughIdempotency = new class extends OperationIdempotencyService
        {
            public function run(string $operation, Model $actor, ?string $key, array $payload, callable $execute): Model
            {
                return $execute();
            }
        };

        $service = new WalletAdjustmentService(app(WalletService::class), $passthroughIdempotency);

        try {
            $service->adjust([
                'customer_id' => $customer->id,
                'kind' => 'disbursement',
                'amount' => '50.00',
                'reason' => 'صرف أكبر من الرصيد لاختبار التراجع الذري',
                'source' => 'admin',
            ], $actor, 'wallet-tx-atomicity-1');
            $this->fail('Expected insufficient wallet balance BusinessException.');
        } catch (BusinessException) {
        }

        $this->assertSame(0, WalletAdjustment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('10.00', (string) $customer->fresh()->wallet_balance);
    }
}
