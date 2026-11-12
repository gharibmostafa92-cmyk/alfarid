<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtime = (string) config('database.connections.pgsql.runtime_role');
        $owner = DB::scalar('SELECT current_user', [], false);
        if ($runtime === '' || $runtime === $owner) {
            if (app()->environment('production')) {
                throw new RuntimeException('Set DB_RUNTIME_ROLE to a login role distinct from the migrator.');
            }

            return;
        }
        if (! DB::scalar('SELECT rolcanlogin FROM pg_roles WHERE rolname = ?', [$runtime], false)) {
            throw new RuntimeException('DB_RUNTIME_ROLE must identify the application login role.');
        }

        $quote = static fn (string $identifier): string => '"'.str_replace('"', '""', $identifier).'"';
        $database = $quote(DB::getDatabaseName());
        $login = $quote($runtime);
        DB::statement("ALTER ROLE {$login} IN DATABASE {$database} SET statement_timeout = '30s'");
        DB::statement("ALTER ROLE {$login} IN DATABASE {$database} SET idle_in_transaction_session_timeout = '60s'");

        // Override legacy cluster-wide budgets in this database.
        $owner = $quote($owner);
        DB::statement("ALTER ROLE {$owner} IN DATABASE {$database} SET statement_timeout = 0");
        DB::statement("ALTER ROLE {$owner} IN DATABASE {$database} SET lock_timeout = '5s'");
    }

    public function down(): void
    {
        // Keep the database-specific role budgets during an application rollback.
    }
};
