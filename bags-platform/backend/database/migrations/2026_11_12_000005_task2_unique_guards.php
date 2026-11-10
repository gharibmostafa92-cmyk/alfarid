<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F10/F11: unique indexes that must not lock writes (CONCURRENTLY => no transaction).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // F10: one allocation row per (notice, order); retries become idempotent instead of double-counting.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS wholesale_allocations_notice_order_unique ON wholesale_payment_allocations (notice_id, order_id)');
        // F11: same bank transfer cannot be approved twice under cosmetic receipt differences.
        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS wholesale_notices_approved_bank_ref_unique ON wholesale_payment_notices (payment_method, lower(btrim(bank_name)), lower(btrim(reference_number))) WHERE status = 'approved'");
        // F26: listing/pruning reconcile runs by recency currently forces a full scan.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS finance_reconcile_runs_created_at_index ON finance_reconcile_runs (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_allocations_notice_order_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_bank_ref_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS finance_reconcile_runs_created_at_index');
    }
};
