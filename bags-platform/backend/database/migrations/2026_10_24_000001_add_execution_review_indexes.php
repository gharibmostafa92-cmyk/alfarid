<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * H1: product_variants(product_id, sort_order) — variants() orders by sort_order.
     * M2: functional case-insensitive uniqueness for slugs/sku/barcode so the
     * application-level LOWER() checks are index-backed and race-safe at the DB level.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_variants_product_sort_index ON product_variants (product_id, sort_order)');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS products_slug_lower_unique ON products (lower(slug))');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS categories_slug_lower_unique ON categories (lower(slug))');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS brands_slug_lower_unique ON brands (lower(slug))');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS product_variants_sku_lower_unique ON product_variants (lower(sku))');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS product_variants_barcode_lower_unique ON product_variants (lower(barcode)) WHERE barcode IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS production_receipts_batch_lower_unique ON production_receipts (lower(batch_number))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS production_receipts_batch_lower_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_variants_barcode_lower_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_variants_sku_lower_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS brands_slug_lower_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS categories_slug_lower_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_slug_lower_unique');

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_variants_product_sort_index');
    }
};
