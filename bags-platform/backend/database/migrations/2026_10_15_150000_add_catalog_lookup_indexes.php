<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * فهارس للعلاقات والترتيب: ‏foreignId()->constrained() على PostgreSQL
     * بيعمل قيد بس من غير فهرس، فالاستعلامات على العلاقات دي كانت Seq Scan.
     * على جدول كبير في الإنتاج شغّل إنشاء الفهارس بـ CREATE INDEX CONCURRENTLY.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index('category_id', 'products_category_id_index');
            $table->index('brand_id', 'products_brand_id_index');
            $table->index('created_by', 'products_created_by_index');
            $table->index('created_at', 'products_created_at_index');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index(['product_id', 'is_active'], 'product_variants_product_active_index');
            $table->index(['product_id', 'retail_price'], 'product_variants_product_retail_index');
            $table->index(['product_id', 'wholesale_price'], 'product_variants_product_wholesale_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_category_id_index');
            $table->dropIndex('products_brand_id_index');
            $table->dropIndex('products_created_by_index');
            $table->dropIndex('products_created_at_index');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('product_variants_product_active_index');
            $table->dropIndex('product_variants_product_retail_index');
            $table->dropIndex('product_variants_product_wholesale_index');
        });
    }
};
