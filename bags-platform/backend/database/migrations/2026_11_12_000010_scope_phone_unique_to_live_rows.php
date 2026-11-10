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
        $this->replacePhoneIndex('customers_phone_live_unique_replacement', ' WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        $dup = DB::selectOne('SELECT 1 AS x FROM customers GROUP BY phone HAVING COUNT(*) > 1 LIMIT 1');
        if ($dup) {
            throw new RuntimeException('Resolve duplicate phones (including soft-deleted rows) before restoring the unscoped index.');
        }
        $this->replacePhoneIndex('customers_phone_all_unique_replacement', '');
    }

    private function replacePhoneIndex(string $replacement, string $predicate): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?) AND indrelid = 'customers'::regclass", [$replacement]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement("DROP INDEX CONCURRENTLY {$replacement}");
        }

        if (! $index?->indisvalid) {
            DB::statement("CREATE UNIQUE INDEX CONCURRENTLY {$replacement} ON customers (phone){$predicate}");
        }

        // Leave the valid replacement in place if dropping or renaming is interrupted.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_phone_unique');
        DB::statement("ALTER INDEX {$replacement} RENAME TO customers_phone_unique");
    }
};
