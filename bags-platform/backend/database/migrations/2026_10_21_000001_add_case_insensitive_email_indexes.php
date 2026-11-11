<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Functional expression indexes on LOWER(email) for users and supplier_users.
 * Avoids sequential scans on case-insensitive login queries and enforces case-insensitive email uniqueness.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['users', 'supplier_users'] as $table) {
            $duplicate = DB::table($table)
                ->selectRaw('LOWER(TRIM(email)) AS normalized')
                ->groupByRaw('LOWER(TRIM(email))')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicate) {
                throw new RuntimeException("Resolve duplicate {$table}.email values (case-insensitive) before adding the unique index.");
            }
            DB::statement("UPDATE {$table} SET email = LOWER(TRIM(email)) WHERE email <> LOWER(TRIM(email))");
        }

        ConcurrentIndex::ensure('users_email_ci_unique', 'ON users (LOWER(email))', unique: true);
        ConcurrentIndex::ensure('supplier_users_email_ci_unique', 'ON supplier_users (LOWER(email))', unique: true);
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_users_email_ci_unique');
        ConcurrentIndex::drop('users_email_ci_unique');
    }
};
