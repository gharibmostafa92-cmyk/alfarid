<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Ensure B-tree lookup indexes exist on products(name_ar), product_variants(barcode),
     * and product_variants(sku) across SQLite (test) and PostgreSQL (production).
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            ConcurrentIndex::ensure('products_name_ar_index', 'ON products (name_ar)');
        } elseif (! Schema::hasIndex('products', ['name_ar'])) {
            Schema::table('products', fn (Blueprint $table) => $table->index('name_ar', 'products_name_ar_index'));
        }

        if (! Schema::hasIndex('product_variants', ['barcode'])) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->index('barcode', 'product_variants_barcode_index');
            });
        }

        if (! Schema::hasIndex('product_variants', ['sku'])) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->index('sku', 'product_variants_sku_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            ConcurrentIndex::drop('products_name_ar_index');
        } elseif (Schema::hasIndex('products', 'products_name_ar_index')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_name_ar_index'));
        }

        if (Schema::hasIndex('product_variants', 'product_variants_barcode_index')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropIndex('product_variants_barcode_index');
            });
        }

        if (Schema::hasIndex('product_variants', 'product_variants_sku_index')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropIndex('product_variants_sku_index');
            });
        }
    }
};
