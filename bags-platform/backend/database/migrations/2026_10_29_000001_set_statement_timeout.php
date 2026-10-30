<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H1: enforce a per-statement budget at the DB role level.
 *
 * Works with PgBouncer transaction pooling. Transaction-local overrides may
 * increase the budget explicitly; bare SET must not leak into pooled sessions.
 * PDO::ATTR_TIMEOUT only bounds
 * connect; this bounds every statement to 30s so a stuck query cannot hold a
 * pooled server connection (default_pool_size=40) indefinitely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("ALTER ROLE CURRENT_USER SET statement_timeout = '30s'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER ROLE CURRENT_USER RESET statement_timeout');
    }
};
