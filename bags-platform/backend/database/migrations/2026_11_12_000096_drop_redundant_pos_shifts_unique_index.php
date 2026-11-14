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

        // pos_shifts_one_open_user on (user_id) WHERE status = 'open'
        // already guarantees at most one open shift per cashier across all branches.
        // pos_shifts_single_open_unique on (user_id, branch_id) WHERE status = 'open' is redundant.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_shifts_single_open_unique');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS pos_shifts_single_open_unique ON pos_shifts (user_id, branch_id) WHERE status = 'open'");
    }
};
