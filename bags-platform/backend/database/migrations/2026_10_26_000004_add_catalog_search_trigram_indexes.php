<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * M7: storefront search is LOWER(col) LIKE '%…%' with a leading wildcard,
     * which a B-tree cannot serve. Trigram GIN indexes on the lowered
     * expressions make it index-assisted. CONCURRENTLY so the product table
     * is never write-locked; pg_trgm is required first.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_name_en_trgm ON products USING GIN (lower(name_en) gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_name_ar_trgm ON products USING GIN (lower(name_ar) gin_trgm_ops)');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS product_variants_sku_trgm ON product_variants USING GIN (lower(sku) gin_trgm_ops) WHERE is_active AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_variants_sku_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_name_ar_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_name_en_trgm');
    }
};
