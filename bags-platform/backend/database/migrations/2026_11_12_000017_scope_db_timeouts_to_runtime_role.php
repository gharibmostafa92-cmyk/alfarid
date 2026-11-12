<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H-3: ALTER ROLE CURRENT_USER configured the migrator cluster-wide. Scope the
 * budgets to the runtime role and to this database only.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $targets = $this->targets();
        if ($targets === null) {
            return;
        }
        [$role, $db] = $targets;
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} SET statement_timeout = '30s'");
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} SET idle_in_transaction_session_timeout = '60s'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $targets = $this->targets();
        if ($targets === null) {
            return;
        }
        [$role, $db] = $targets;
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} RESET statement_timeout");
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} RESET idle_in_transaction_session_timeout");
    }

    /** @return array{string, string}|null */
    private function targets(): ?array
    {
        $quote = fn (string $id): string => '"'.str_replace('"', '""', $id).'"';
        $configured = (string) (config('database.connections.pgsql.runtime_role') ?: '');
        $current = (string) DB::scalar('SELECT current_user');
        if ($configured === '' || $configured === $current) {
            // F-02: an empty setting (or the migrator's own role) would scope
            // the timeouts — and every other role-targeted grant — at the
            // table owner instead of the application login.
            if (app()->environment('production')) {
                throw new RuntimeException('Set DB_RUNTIME_ROLE to the application login role in production; it must not be empty or the migrator role.');
            }
            return null;
        }
        if (! DB::scalar('SELECT rolcanlogin FROM pg_roles WHERE rolname = ?', [$configured], false)) {
            throw new RuntimeException('DB_RUNTIME_ROLE must identify the application login role.');
        }

        return [$quote($configured), $quote(DB::getDatabaseName())];
    }
};
