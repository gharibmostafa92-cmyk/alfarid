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
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER ROLE CURRENT_USER RESET idle_in_transaction_session_timeout');
        }
    }
};
