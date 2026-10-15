<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 6.1: جداول وحقول واجهة المتجر.
 * - banners: بانرات تسويقية بمواضع ونطاقات زمنية.
 * - products.sales_count: عدّاد مبيعات لأقسام «الأكثر مبيعًا» (يُحدَّث مع الطلبات لاحقًا).
 * - product_variants.compare_at_price: السعر قبل الخصم لاكتشاف المنتجات المخفضة.
 * ملحوظة: «منتج نشط» في المتجر = status منشور (لا يوجد is_active على المنتجات أصلًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('image_path');
            $table->enum('link_type', ['product', 'category', 'brand', 'external', 'none'])->default('none');
            $table->string('link_value')->nullable();
            $table->enum('position', ['main_slider', 'middle_banner', 'popup'])->default('main_slider');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['position', 'is_active', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('sales_count')->default(0)->index();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('compare_at_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('compare_at_price');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('sales_count');
        });

        Schema::dropIfExists('banners');
    }
};
