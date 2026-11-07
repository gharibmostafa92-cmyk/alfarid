<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Specialized PostgreSQL search indexes for order management:
 * - text_pattern_ops on order_number and guest_phone for btree prefix LIKE queries.
 * - Trigram GIN index on guest_name for fuzzy substring matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_order_number_pattern_index ON orders (order_number text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_guest_phone_pattern_index ON orders (guest_phone text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_guest_name_trgm_index ON orders USING GIN (guest_name gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS orders_order_number_pattern_index');
        DB::statement('DROP INDEX IF EXISTS orders_guest_phone_pattern_index');
        DB::statement('DROP INDEX IF EXISTS orders_guest_name_trgm_index');
    }
};
