<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Adds checked_at timestamp to reconcile work queue to prevent starvation of older ledger rows.
     * with a permanent problem occupied the LIMIT window forever and nearby
     * keys starved. checked_at lets the picker prefer least-recently-checked
     * keys; NULLS FIRST keeps brand-new keys first.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_reconcile_work ADD COLUMN IF NOT EXISTS checked_at timestamptz NULL');
        // F-16: ConcurrentIndex::ensure recovers an INVALID leftover from a
        // failed concurrent build instead of skipping it forever.
        ConcurrentIndex::ensure('inventory_reconcile_work_branch_checked_index', 'ON inventory_reconcile_work (branch_id, checked_at NULLS FIRST, key)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('inventory_reconcile_work_branch_checked_index');
        DB::statement('ALTER TABLE inventory_reconcile_work DROP COLUMN IF EXISTS checked_at');
    }
};
