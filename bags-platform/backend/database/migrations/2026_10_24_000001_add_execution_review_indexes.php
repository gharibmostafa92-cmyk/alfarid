<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Composite index on product_variants(product_id, sort_order) for catalog ordering.
     * Functional case-insensitive uniqueness for slugs/sku/barcode so the
     * application-level LOWER() checks are index-backed and race-safe at the DB level.
     */
    public function up(): void
    {
        ConcurrentIndex::ensure('product_variants_product_sort_index', 'ON product_variants (product_id, sort_order)');

        ConcurrentIndex::ensure('products_slug_lower_unique', 'ON products (lower(slug))', unique: true);
        ConcurrentIndex::ensure('categories_slug_lower_unique', 'ON categories (lower(slug))', unique: true);
        ConcurrentIndex::ensure('brands_slug_lower_unique', 'ON brands (lower(slug))', unique: true);
        ConcurrentIndex::ensure('product_variants_sku_lower_unique', 'ON product_variants (lower(sku))', unique: true);
        ConcurrentIndex::ensure('product_variants_barcode_lower_unique', 'ON product_variants (lower(barcode)) WHERE barcode IS NOT NULL', unique: true);
        ConcurrentIndex::ensure('production_receipts_batch_lower_unique', 'ON production_receipts (lower(batch_number))', unique: true);
    }

    public function down(): void
    {
        ConcurrentIndex::drop('production_receipts_batch_lower_unique');
        ConcurrentIndex::drop('product_variants_barcode_lower_unique');
        ConcurrentIndex::drop('product_variants_sku_lower_unique');
        ConcurrentIndex::drop('brands_slug_lower_unique');
        ConcurrentIndex::drop('categories_slug_lower_unique');
        ConcurrentIndex::drop('products_slug_lower_unique');

        ConcurrentIndex::drop('product_variants_product_sort_index');
    }
};
