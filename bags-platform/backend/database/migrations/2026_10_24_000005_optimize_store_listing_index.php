<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Optimizes storefront catalog index filtering on published status, so the plain index
     * on the generated products.is_active column only taxes writes.
     * Drop the index (keep the column) and add the partial index that
     * actually serves the store's newest-first listing order.
     *
     * F-16: ConcurrentIndex::ensure recovers an INVALID leftover from a
     * failed concurrent build instead of skipping it forever.
     */
    public function up(): void
    {
        ConcurrentIndex::drop('products_is_active_index');
        ConcurrentIndex::ensure('products_store_listing_index', "ON products (created_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')");
    }

    public function down(): void
    {
        ConcurrentIndex::drop('products_store_listing_index');
        ConcurrentIndex::ensure('products_is_active_index', 'ON products (is_active)');
    }
};
