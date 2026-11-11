<?php

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyDatabaseSchema extends Command
{
    protected $signature = 'db:verify-schema';

    protected $description = 'Check PostgreSQL for invalid indexes and unvalidated constraints';

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
            ORDER BY issue, table_name, object_name
            SQL);

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
}
