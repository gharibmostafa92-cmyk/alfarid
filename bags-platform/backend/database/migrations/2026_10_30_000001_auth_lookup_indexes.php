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
        if (DB::table('users')->selectRaw('LOWER(email)')->groupByRaw('LOWER(email)')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve case-insensitive duplicate user emails before migrating.');
        }
        foreach ([
            'customers_email_login_idx' => 'CREATE INDEX CONCURRENTLY customers_email_login_idx ON customers (LOWER(email)) WHERE deleted_at IS NULL',
            'users_email_ci_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY users_email_ci_unique ON users (LOWER(email))',
        ] as $name => $sql) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement("DROP INDEX CONCURRENTLY {$name}");
            }
            if ($index === null || ! $index->indisvalid) {
                DB::statement($sql);
            }
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_phone_index');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS customers_phone_index ON customers (phone)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_email_login_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_email_ci_unique');
        }
    }
};
