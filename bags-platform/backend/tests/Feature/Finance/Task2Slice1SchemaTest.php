<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Branch;
use App\Models\Treasury;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task2Slice1SchemaTest extends TestCase
{
    use LazilyRefreshDatabase;


    public function test_critical_01_treasury_transactions_type_check_is_dropped_and_customer_receipt_reversal_allowed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only');
        }

        // Verify the old constraint is gone
        $oldConstraint = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'treasury_transactions'::regclass
              AND conname = 'treasury_transactions_type_check'
        ");
        $this->assertNull($oldConstraint, 'Obsolete treasury_transactions_type_check constraint should be dropped.');

        // Verify treasury_transactions_type_sign_check accepts customer_receipt_reversal with negative amount
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'T2_TEST_B1'],
            ['name' => 'Branch Test', 'is_active' => true]
        );
        $treasury = Treasury::query()->create([
            'code' => 'T2_BANK_'.uniqid(),
            'name' => 'Bank Test Treasury',
            'type' => 'bank_account',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $treasury->forceFill(['current_balance' => '1000.00'])->save();

        $user = \App\Models\User::query()->firstOrCreate(
            ['email' => 't2_treasury_test@example.com'],
            ['name' => 'Tester', 'password' => 'secret123']
        );

        $treasuryService = app(\App\Services\Pos\TreasuryService::class);
        $entry = $treasuryService->record([
            'treasury_id' => $treasury->id,
            'amount' => '-50.00',
            'type' => 'customer_receipt_reversal',
            'created_by' => $user->id,
            'reference_type' => 'customer_receipt',
            'reference_id' => 99999,
        ]);
        $this->assertNotNull($entry);
        $this->assertSame('customer_receipt_reversal', $entry->type->value ?? (string) $entry->type);
    }

    public function test_medium_02_composite_indexes_exist_and_are_valid(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only');
        }

        $treasuryIdx = DB::selectOne("
            SELECT i.indisvalid
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relname = 'treasury_transactions_treasury_id_id_idx'
        ");
        $this->assertNotNull($treasuryIdx, 'treasury_transactions_treasury_id_id_idx should exist.');
        $this->assertTrue($treasuryIdx->indisvalid, 'treasury_transactions_treasury_id_id_idx should be valid.');

        $walletIdx = DB::selectOne("
            SELECT i.indisvalid
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relname = 'customer_wallet_transactions_customer_id_id_idx'
        ");
        $this->assertNotNull($walletIdx, 'customer_wallet_transactions_customer_id_id_idx should exist.');
        $this->assertTrue($walletIdx->indisvalid, 'customer_wallet_transactions_customer_id_id_idx should be valid.');
    }

    public function test_critical_02_wallet_transactions_reference_alias_unique_includes_customer_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only');
        }

        // Verify index definition includes customer_id
        $indexDef = DB::selectOne("
            SELECT pg_get_indexdef(i.indexrelid) as def
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relname = 'wallet_transactions_reference_alias_unique'
        ");
        $this->assertNotNull($indexDef, 'wallet_transactions_reference_alias_unique index must exist.');
        $this->assertStringContainsString('customer_id', $indexDef->def, 'Index definition must include customer_id.');

        // Test inserting two different customers for same order reference and type succeeds
        $c1 = \App\Models\Customer::factory()->create(['wallet_balance' => '500.00']);
        $c2 = \App\Models\Customer::factory()->create(['wallet_balance' => '500.00']);

        $tx1 = \App\Models\CustomerWalletTransaction::query()->create([
            'customer_id' => $c1->id,
            'type' => 'checkout_payment',
            'amount' => '-100.00',
            'balance_after' => '400.00',
            'reference_type' => 'order',
            'reference_id' => 88888,
        ]);
        $this->assertNotNull($tx1);

        $tx2 = \App\Models\CustomerWalletTransaction::query()->create([
            'customer_id' => $c2->id,
            'type' => 'checkout_payment',
            'amount' => '-50.00',
            'balance_after' => '450.00',
            'reference_type' => 'order',
            'reference_id' => 88888,
        ]);
        $this->assertNotNull($tx2);
    }

    public function test_high_04_verify_database_schema_and_constraints(): void
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\Maintenance\VerifyDatabaseSchema::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('EXPECTED_CONSTRAINTS', $constants);
        $this->assertContains('treasuries_nonnegative_cash', $constants['EXPECTED_CONSTRAINTS']);

        $this->assertArrayHasKey('EXPECTED_INDEXES', $constants);
        $this->assertContains('wallet_transactions_reference_alias_unique', $constants['EXPECTED_INDEXES']);
        $this->assertContains('treasury_transactions_reference_unique', $constants['EXPECTED_INDEXES']);
        $this->assertContains('treasury_transactions_opening_balance_unique', $constants['EXPECTED_INDEXES']);
        $this->assertContains('wallet_adjustments_one_reversal_idx', $constants['EXPECTED_INDEXES']);
        $this->assertContains('pos_shifts_one_open_user', $constants['EXPECTED_INDEXES']);
        $this->assertContains('pos_shifts_one_unsettled_drawer', $constants['EXPECTED_INDEXES']);
    }

    public function test_medium_05_finance_reconcile_runs_has_created_at_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only');
        }

        $idx = DB::selectOne("
            SELECT i.indisvalid
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relname = 'finance_reconcile_runs_created_at_idx'
        ");
        $this->assertNotNull($idx, 'finance_reconcile_runs_created_at_idx should exist.');
        $this->assertTrue($idx->indisvalid, 'finance_reconcile_runs_created_at_idx should be valid.');
    }
}
