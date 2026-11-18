<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HIGH-04: PostgreSQL-only migrations must no-op on other drivers so the
 * SQLite/CI schema pipeline never executes PostgreSQL DDL.
 */
class Task2MigrationDriverGuardsTest extends TestCase
{
    /** @return list<string> */
    private function guardedMigrations(): array
    {
        return [
            '2026_11_12_000025_scope_inventory_reconciliation_updates.php',
            '2026_11_12_000027_allow_runtime_number_synchronization.php',
            '2026_11_12_000032_restrict_financial_history_deletes.php',
            '2026_11_12_000041_link_inventory_reconcile_runs_to_branches.php',
            '2026_11_12_000042_align_monetary_precision.php',
            '2026_11_12_000064_align_related_financial_amount_precision.php',
            '2026_11_12_000068_treasury_ledger_integrity.php',
            '2026_11_13_000001_remove_numbering_subtransactions.php',
        ];
    }

    public function test_postgres_only_migrations_no_op_on_other_drivers(): void
    {
        foreach ($this->guardedMigrations() as $file) {
            $migration = require database_path('migrations/'.$file);

            DB::shouldReceive('getDriverName')->andReturn('sqlite');
            DB::shouldReceive('statement')->never();
            DB::shouldReceive('unprepared')->never();
            DB::shouldReceive('transaction')->never();
            DB::shouldReceive('select')->never();

            $migration->up();
            $migration->down();

            $this->addToAssertionCount(1);
        }
    }
}
