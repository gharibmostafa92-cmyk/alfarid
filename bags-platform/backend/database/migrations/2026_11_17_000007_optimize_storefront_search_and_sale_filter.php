<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('catalog_has_retail_discount')->default(false);
        });

        // Force the existing snapshot freshness guard to use the live query
        // until the scheduled refresher has populated the new discount flag.
        DB::table('products')->whereNotNull('catalog_price_refreshed_at')->update(['catalog_price_refreshed_at' => null]);

        // The raw-column trigram indexes support PostgreSQL ILIKE. Their
        // lower(name_*) twins duplicate write and storage cost without adding
        // coverage after the storefront switches to case-insensitive whereLike.
        ConcurrentIndex::drop('products_name_en_trgm');
        ConcurrentIndex::drop('products_name_ar_trgm');
        ConcurrentIndex::ensure('products_store_discounted_index', 'ON products (catalog_has_retail_discount, status, sales_channel, id)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('products_store_discounted_index');
        ConcurrentIndex::ensure('products_name_en_trgm', 'ON products USING GIN (lower(name_en) gin_trgm_ops)');
        ConcurrentIndex::ensure('products_name_ar_trgm', 'ON products USING GIN (lower(name_ar) gin_trgm_ops)');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('catalog_has_retail_discount');
        });
    }
};
