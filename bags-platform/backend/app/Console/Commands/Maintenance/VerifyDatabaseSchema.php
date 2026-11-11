<?php

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyDatabaseSchema extends Command
{
    protected $signature = 'db:verify-schema';

    protected $description = 'Check PostgreSQL for invalid indexes, unvalidated constraints, disabled or missing guards, and FKs without a leading index';

    /**
     * Ledger and allocation guard triggers that must exist and stay enabled.
     * A silently dropped or disabled guard reopens double-spend and
     * cap-bypass paths with no application-level signal.
     *
     * @var list<string>
     */
    private const EXPECTED_TRIGGERS = [
        'wallet_adjustments_immutable',
        'wallet_adjustments_no_truncate',
        'wallet_adjustments_validate_reversal',
        'wholesale_allocation_check_totals',
        'wholesale_notice_check_totals',
        'wholesale_order_check_totals',
        'wholesale_allocation_lock_parents',
        'wholesale_notice_lock_orders',
        'wholesale_order_lock_notices',
        'stock_levels_reconciliation_work',
        'stock_levels_reconciliation_update',
        'stock_movements_reconciliation_work',
    ];

    /** @var list<string> */
    private const EXPECTED_CONSTRAINTS = [
        'wallet_adjustments_amount_positive',
        'orders_total_formula',
    ];

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('Schema verification requires PostgreSQL.');

            return self::FAILURE;
        }

        $issues = $connection->select(<<<'SQL'
            SELECT 'Invalid index' AS issue, indexed_table.relname AS table_name, index_object.relname AS object_name
            FROM pg_catalog.pg_index AS index_state
            JOIN pg_catalog.pg_class AS index_object ON index_object.oid = index_state.indexrelid
            JOIN pg_catalog.pg_class AS indexed_table ON indexed_table.oid = index_state.indrelid
            JOIN pg_catalog.pg_namespace AS namespace ON namespace.oid = indexed_table.relnamespace
            WHERE namespace.nspname = current_schema()
                AND (NOT index_state.indisvalid OR NOT index_state.indisready OR NOT index_state.indislive)
            UNION ALL
            SELECT 'Unvalidated constraint', constrained_table.relname, constraint_state.conname
            FROM pg_catalog.pg_constraint AS constraint_state
            JOIN pg_catalog.pg_class AS constrained_table ON constrained_table.oid = constraint_state.conrelid
            JOIN pg_catalog.pg_namespace AS namespace ON namespace.oid = constrained_table.relnamespace
            WHERE namespace.nspname = current_schema() AND NOT constraint_state.convalidated
            UNION ALL
            SELECT 'Disabled trigger', triggered_table.relname, trigger_state.tgname
            FROM pg_catalog.pg_trigger AS trigger_state
            JOIN pg_catalog.pg_class AS triggered_table ON triggered_table.oid = trigger_state.tgrelid
            JOIN pg_catalog.pg_namespace AS namespace ON namespace.oid = triggered_table.relnamespace
            WHERE namespace.nspname = current_schema()
                AND NOT trigger_state.tgisinternal
                AND trigger_state.tgenabled = 'D'
            ORDER BY issue, table_name, object_name
            SQL);

        // F-23: a guard that was never created (skipped migration) or dropped
        // later is indistinguishable from a healthy schema without an
        // explicit presence check.
        $missing = $this->missingGuards($connection);
        foreach ($missing as $row) {
            $issues[] = $row;
        }

        // F-23/F-17: FKs without a leading index turn parent deletes into
        // sequential child scans. Reported as warnings: the remaining tail
        // needs per-table query-plan review before indexing.
        $this->reportUnindexedForeignKeys($connection);

        if ($issues === []) {
            $this->info('PostgreSQL indexes and constraints are valid.');

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->error("{$issue->issue}: {$issue->table_name}.{$issue->object_name}");
        }

        logger()->error('PostgreSQL schema verification failed.', ['issues' => $issues]);

        return self::FAILURE;
    }

    /**
     * @return list<object{issue: string, table_name: string, object_name: string}>
     */
    private function missingGuards(\Illuminate\Database\Connection $connection): array
    {
        $missing = [];
        $existingTriggers = collect($connection->select(<<<'SQL'
            SELECT t.tgname FROM pg_catalog.pg_trigger AS t
            JOIN pg_catalog.pg_class AS c ON c.oid = t.tgrelid
            JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
            WHERE NOT t.tgisinternal AND n.nspname = current_schema()
            SQL))->map(fn ($row) => $row->tgname)->all();
        foreach (self::EXPECTED_TRIGGERS as $trigger) {
            if (! in_array($trigger, $existingTriggers, true)) {
                $missing[] = (object) ['issue' => 'Missing guard trigger', 'table_name' => '-', 'object_name' => $trigger];
            }
        }
        $existingConstraints = collect($connection->select(<<<'SQL'
            SELECT c.conname FROM pg_catalog.pg_constraint AS c
            JOIN pg_catalog.pg_namespace AS n ON n.oid = c.connamespace
            WHERE n.nspname = current_schema()
            SQL))->map(fn ($row) => $row->conname)->all();
        foreach (self::EXPECTED_CONSTRAINTS as $constraint) {
            if (! in_array($constraint, $existingConstraints, true)) {
                $missing[] = (object) ['issue' => 'Missing guard constraint', 'table_name' => '-', 'object_name' => $constraint];
            }
        }

        return $missing;
    }

    private function reportUnindexedForeignKeys(\Illuminate\Database\Connection $connection): void
    {
        $gaps = $connection->select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, c.conname AS object_name
            FROM pg_catalog.pg_constraint AS c
            WHERE c.contype = 'f' AND c.connamespace = current_schema()::regnamespace
                AND NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_index AS i
                    WHERE i.indrelid = c.conrelid AND i.indisvalid
                        AND (i.indkey::smallint[]::int[])[1:array_length(c.conkey, 1)] = c.conkey::int[]
                )
            ORDER BY table_name, object_name
            SQL);
        foreach ($gaps as $gap) {
            $this->warn("FK without leading index: {$gap->table_name}.{$gap->object_name}");
        }
    }
}
