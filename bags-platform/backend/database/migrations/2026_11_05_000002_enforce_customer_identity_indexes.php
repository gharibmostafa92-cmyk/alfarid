<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $dup = DB::selectOne("SELECT 1 AS x FROM customers WHERE email IS NOT NULL AND deleted_at IS NULL
            AND type IN ('wholesale', 'distributor') GROUP BY LOWER(email) HAVING COUNT(*) > 1 LIMIT 1");
        if ($dup) {
            throw new RuntimeException('Resolve duplicate trader emails before adding customers_business_email_unique.');
        }
        $c = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        // Customer email serves as a login identifier before phone verification.
        DB::statement("CREATE UNIQUE INDEX {$c}IF NOT EXISTS customers_business_email_unique ON customers (LOWER(email))
            WHERE email IS NOT NULL AND deleted_at IS NULL AND type IN ('wholesale', 'distributor')");

        // Redundant with customers_phone_unique and customers_phone_pattern_index.
        DB::statement("DROP INDEX {$c}IF EXISTS customers_phone_index");

        // Fail-safe default matching initial customer schema.
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
    }

    public function down(): void
    {
        $c = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
        DB::statement("CREATE INDEX {$c}IF NOT EXISTS customers_phone_index ON customers (phone)");
        DB::statement("DROP INDEX {$c}IF EXISTS customers_business_email_unique");
    }
};
