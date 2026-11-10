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
        [$role, $db] = $this->targets();
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} SET statement_timeout = '30s'");
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} SET idle_in_transaction_session_timeout = '60s'");
    }
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        [$role, $db] = $this->targets();
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} RESET statement_timeout");
        DB::unprepared("ALTER ROLE {$role} IN DATABASE {$db} RESET idle_in_transaction_session_timeout");
    }
    /** @return array{string, string} */
    private function targets(): array
    {
        $quote = fn (string $id): string => '"'.str_replace('"', '""', $id).'"';
        $role = (string) (config('database.connections.pgsql.runtime_role') ?: DB::scalar('SELECT current_user'));
        return [$quote($role), $quote(DB::getDatabaseName())];
    }
};
