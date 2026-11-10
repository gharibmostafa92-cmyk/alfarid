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
        // F10: prevent more than one allocation row per (notice, order).
        $this->ensureIndex('wholesale_allocations_notice_order_unique', 'wholesale_payment_allocations', '(notice_id, order_id)', unique: true);
        // F11: same bank transfer cannot be approved twice under cosmetic receipt differences.
        $this->ensureIndex('wholesale_notices_approved_bank_ref_unique', 'wholesale_payment_notices', "(payment_method, lower(btrim(bank_name)), lower(btrim(reference_number))) WHERE status = 'approved'", unique: true);
        // F26: listing/pruning reconcile runs by recency currently forces a full scan.
        $this->ensureIndex('finance_reconcile_runs_created_at_index', 'finance_reconcile_runs', '(created_at)', unique: false);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_allocations_notice_order_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_bank_ref_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS finance_reconcile_runs_created_at_index');
    }

    private function ensureIndex(string $name, string $table, string $definition, bool $unique): void
    {
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?) AND indrelid = to_regclass(?)', [$name, $table]);
        if ($index?->indisvalid) {
            return;
        }

        if ($index !== null) {
            DB::statement("DROP INDEX CONCURRENTLY {$name}");
        }

        $indexType = $unique ? 'UNIQUE INDEX' : 'INDEX';
        DB::statement("CREATE {$indexType} CONCURRENTLY {$name} ON {$table} {$definition}");
    }
};
