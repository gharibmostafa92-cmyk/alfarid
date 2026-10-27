<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach ($this->indexes() as $name => $definition) {
            ConcurrentIndex::ensure($name, $definition);
        }
    }

    public function down(): void
    {
        ConcurrentIndex::drop('products_catalog_retail_price_index');
        ConcurrentIndex::drop('products_occasions_gin');
    }

    private function indexes(): array
    {
        return [
            'products_occasions_gin' => 'ON products USING GIN (occasions)',
            'product_variants_active_color_en' => 'ON product_variants (LOWER(color_name_en), product_id) WHERE is_active AND deleted_at IS NULL',
            'product_variants_active_color_ar' => 'ON product_variants (color_name_ar, product_id) WHERE is_active AND deleted_at IS NULL',
            'products_store_featured_index' => "ON products (is_featured DESC, sales_count DESC, created_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
            'products_store_best_selling_index' => "ON products (sales_count DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
            'products_name_en_trgm' => 'ON products USING GIN (lower(name_en) gin_trgm_ops)',
            'products_name_ar_trgm' => 'ON products USING GIN (lower(name_ar) gin_trgm_ops)',
            'product_variants_sku_trgm' => 'ON product_variants USING GIN (lower(sku) gin_trgm_ops) WHERE is_active AND deleted_at IS NULL',
            'products_catalog_retail_price_index' => "ON products (catalog_min_retail_price, id) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
        ];
    }
};
