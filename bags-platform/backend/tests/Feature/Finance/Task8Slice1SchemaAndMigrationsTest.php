<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Pos\TreasuryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Task8Slice1SchemaAndMigrationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_bank_overdraft_is_bounded_and_cannot_be_funded_by_a_late_opening_entry(): void
    {
        config(['treasury.bank_overdraft_limit' => '20.00']);
        $actor = User::factory()->create();
        $bank = Treasury::query()->create([
            'name' => 'Bank', 'code' => 'OVERDRAFT-TEST', 'type' => TreasuryType::BankAccount, 'is_active' => true,
        ]);
        $service = app(TreasuryService::class);
        $record = fn (TreasuryTransactionType $type, string $amount) => $service->record([
            'treasury_id' => $bank->id, 'type' => $type, 'amount' => $amount, 'created_by' => $actor->id,
        ]);
        $record(TreasuryTransactionType::TransferOut, '-20.00');
        foreach ([[TreasuryTransactionType::TransferOut, '-0.01'], [TreasuryTransactionType::OpeningBalance, '10.00']] as [$type, $amount]) {
            try {
                $record($type, $amount);
                $this->fail('The treasury entry must be rejected.');
            } catch (\App\Exceptions\BusinessException) {
                $this->assertSame('-20.00', $bank->refresh()->current_balance);
            }
        }
    }

    public function test_f01_treasury_transactions_immutability_sign_constraint_and_opening_balance_uniqueness(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::create([
            'name' => 'Test Safe',
            'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);

        $tx = app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '100.00',
            'created_by' => $staff->id,
        ]);

        // Immutability trigger blocks direct UPDATE.
        try {
            DB::transaction(fn () => DB::table('treasury_transactions')->where('id', $tx->id)->update(['amount' => '999.00']));
            $this->fail('Expected immutability trigger to reject UPDATE on treasury_transactions.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
        }

        // Duplicate account-level opening_balance (NULL reference) must violate unique partial index.
        try {
            DB::transaction(fn () => DB::table('treasury_transactions')->insert([
                'treasury_id' => $treasury->id,
                'type' => 'opening_balance',
                'amount' => '50.00',
                'balance_after' => '150.00',
                'reference_type' => null,
                'reference_id' => null,
                'created_by' => $staff->id,
                'created_at' => now(),
            ]));
            $this->fail('Expected partial unique index to block duplicate NULL-reference opening_balance.');
        } catch (QueryException $e) {
            $this->assertSame('23505', (string) ($e->errorInfo[0] ?? ''));
        }

        // Shift-scoped opening_balance rows with explicit reference remain allowed per shift.
        DB::table('treasury_transactions')->insert([
            'treasury_id' => $treasury->id,
            'type' => 'opening_balance',
            'amount' => '25.00',
            'balance_after' => '125.00',
            'reference_type' => 'pos_shift',
            'reference_id' => 90001,
            'created_by' => $staff->id,
            'created_at' => now(),
        ]);

        // Constraints must be validated and legacy broken constraint must stay dropped.
        $typeSign = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conrelid = 'treasury_transactions'::regclass AND conname = 'treasury_transactions_type_sign_check'");
        $this->assertNotNull($typeSign);
        $this->assertTrue((bool) $typeSign->convalidated);

        $approverRequired = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conrelid = 'treasury_transfers'::regclass AND conname = 'treasury_transfers_approver_required'");
        $this->assertNotNull($approverRequired);
        $this->assertTrue((bool) $approverRequired);

        $legacySeparateApprover = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'treasury_transfers'::regclass AND conname = 'treasury_transfers_separate_approver'");
        $this->assertNull($legacySeparateApprover);
    }

    public function test_f10_stock_movements_inventory_last_activity_index_exists_and_schema_verifies(): void
    {
        $index = DB::selectOne(<<<'SQL'
            SELECT i.indisvalid, i.indisready, i.indislive
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relnamespace = current_schema()::regnamespace
              AND c.relname = 'stock_movements_inventory_last_activity_idx'
            SQL);

        $this->assertNotNull($index);
        $this->assertTrue((bool) $index->indisvalid);
        $this->assertTrue((bool) $index->indisready);
        $this->assertTrue((bool) $index->indislive);

        $exitCode = Artisan::call('db:verify-schema');
        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_f15_postgres_specific_migrations_include_driver_guards(): void
    {
        $paths = [
            'database/migrations/2026_11_10_000002_backfill_wallet_transaction_types.php',
            'database/migrations/2026_11_12_000022_protect_wallet_adjustment_reversals.php',
            'database/migrations/2026_11_12_000023_unique_wallet_reference_aliases.php',
            'database/migrations/2026_10_24_000009_harden_pos_and_treasury.php',
            'database/migrations/2026_10_28_000008_add_expense_reversal_type.php',
        ];

        foreach ($paths as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $this->assertIsString($contents);
            $this->assertStringContainsString("DB::getDriverName() !== 'pgsql'", $contents, "{$relativePath} is missing PostgreSQL driver guard.");
        }
    }
}
