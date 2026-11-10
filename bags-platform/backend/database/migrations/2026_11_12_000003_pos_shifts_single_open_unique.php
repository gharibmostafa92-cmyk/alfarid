<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M8: enforce a single open shift per cashier per branch.
     * CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('pos_shifts_single_open_unique') AND indrelid = 'pos_shifts'::regclass");
        if ($index?->indisvalid) {
            return;
        }

        // PostgreSQL leaves an invalid index behind when a concurrent build fails.
        if ($index !== null) {
            DB::statement('DROP INDEX CONCURRENTLY pos_shifts_single_open_unique');
        }

        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY pos_shifts_single_open_unique ON pos_shifts (user_id, branch_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_shifts_single_open_unique');
    }
};
