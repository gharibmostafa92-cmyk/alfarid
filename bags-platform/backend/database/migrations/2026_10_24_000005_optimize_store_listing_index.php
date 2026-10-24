<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * F20: every storefront listing filters by status, so the plain index
     * on the generated products.is_active column only taxes writes.
     * Drop the index (keep the column) and add the partial index that
     * actually serves the store's newest-first listing order.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_is_active_index');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS products_store_listing_index ON products (created_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_store_listing_index');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_is_active_index ON products (is_active)');
    }
};
