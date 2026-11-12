<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
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
        // F-16: ConcurrentIndex::ensure recovers an INVALID leftover from a
        // failed concurrent build (and verifies the definition) instead of
        // skipping it forever.
        ConcurrentIndex::ensure('customers_business_email_unique', "ON customers (LOWER(email)) WHERE email IS NOT NULL AND deleted_at IS NULL AND type IN ('wholesale', 'distributor')", unique: true);

        // Redundant with customers_phone_unique and customers_phone_pattern_index.
        ConcurrentIndex::drop('customers_phone_index');

        // Fail-safe default matching initial customer schema.
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
    }

    public function down(): void
    {
        ConcurrentIndex::ensure('customers_phone_index', 'ON customers (phone)');
        ConcurrentIndex::drop('customers_business_email_unique');
    }
};
