<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER ROLE CURRENT_USER SET idle_in_transaction_session_timeout = '60s'");
        }
    }

    public function down(): void
    {
        // H-3: a rollback on alfarid_testing must not strip production budgets
        // from the shared role. Scoped settings are handled by 2026_11_12_000017.
    }
};
