<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * L13: the incremental queue previously scanned ORDER BY key, so a key
     * with a permanent problem occupied the LIMIT window forever and nearby
     * keys starved. checked_at lets the picker prefer least-recently-checked
     * keys; NULLS FIRST keeps brand-new keys first.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_reconcile_work ADD COLUMN IF NOT EXISTS checked_at timestamptz NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS inventory_reconcile_work_branch_checked_index
            ON inventory_reconcile_work (branch_id, checked_at NULLS FIRST, key)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS inventory_reconcile_work_branch_checked_index');
        DB::statement('ALTER TABLE inventory_reconcile_work DROP COLUMN IF EXISTS checked_at');
    }
};
