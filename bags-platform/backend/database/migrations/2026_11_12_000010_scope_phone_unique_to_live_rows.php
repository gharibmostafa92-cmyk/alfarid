<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F13: customers_phone_unique blocked re-registration after soft-delete while
     * the commercial email index excludes deleted rows. Scope the phone unique
     * index to live rows so both identifiers share one soft-delete policy.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $dup = DB::selectOne('SELECT 1 AS x FROM customers WHERE deleted_at IS NULL GROUP BY phone HAVING COUNT(*) > 1 LIMIT 1');
        if ($dup) {
            throw new RuntimeException('Resolve duplicate live phones before scoping customers_phone_unique to live rows.');
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_phone_unique');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_phone_unique ON customers (phone) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        $dup = DB::selectOne('SELECT 1 AS x FROM customers GROUP BY phone HAVING COUNT(*) > 1 LIMIT 1');
        if ($dup) {
            throw new RuntimeException('Resolve duplicate phones (including soft-deleted rows) before restoring the unscoped index.');
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_phone_unique');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_phone_unique ON customers (phone)');
    }
};
