<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops redundant indexes to reduce write overhead without degrading read performance:
 * - stocktake_items_unique_variant duplicates the composite unique constraint on (stocktake_id, product_variant_id).
 * - stock_levels_product_variant_id_index is covered by the leading column of (product_variant_id, branch_id, location_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS stocktake_items_unique_variant');
        DB::statement('DROP INDEX IF EXISTS stock_levels_product_variant_id_index');
    }

    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX stocktake_items_unique_variant ON stocktake_items (stocktake_id, product_variant_id)');
        DB::statement('CREATE INDEX stock_levels_product_variant_id_index ON stock_levels (product_variant_id)');
    }
};
