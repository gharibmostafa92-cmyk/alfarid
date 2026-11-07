<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Admin/AI product search is name_ar/name_en ILIKE '%…%' with a
     * leading wildcard, which a B-tree cannot serve. The existing trigram
     * indexes sit on lower(name_*) and only serve LOWER(col) LIKE queries;
     * raw-column GIN indexes serve the ILIKE queries the backoffice and
     * the assistant actually run. CONCURRENTLY so products is never
     * write-locked; pg_trgm is required first.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_name_ar_ilike_trgm ON products USING GIN (name_ar gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_name_en_ilike_trgm ON products USING GIN (name_en gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_name_en_ilike_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_name_ar_ilike_trgm');
    }
};
