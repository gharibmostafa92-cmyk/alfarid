<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // [MEDIUM-03] Drop duplicate index (finance_reconcile_runs_created_at_index already covers created_at).
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS finance_reconcile_runs_created_at_idx');

        // Rebuild orders_pending_expiry_idx with created_at leading for range scans.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_pending_expiry_idx');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_pending_expiry_idx ON orders (created_at, id) INCLUDE (payment_method) WHERE status = 'pending'");

        // [INFO-01] Keep the high-churn jobs table compact for workers and Prometheus count(*) scrapes.
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE jobs SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_vacuum_cost_limit = 1000)');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE jobs RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_cost_limit)');
        });

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_pending_expiry_idx');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_pending_expiry_idx ON orders (id, created_at, payment_method) WHERE status = 'pending'");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS finance_reconcile_runs_created_at_idx ON finance_reconcile_runs (created_at)');
    }
};
