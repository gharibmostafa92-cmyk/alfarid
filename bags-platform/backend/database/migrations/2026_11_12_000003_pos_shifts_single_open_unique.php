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
        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS pos_shifts_single_open_unique ON pos_shifts (user_id, branch_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_shifts_single_open_unique');
    }
};
