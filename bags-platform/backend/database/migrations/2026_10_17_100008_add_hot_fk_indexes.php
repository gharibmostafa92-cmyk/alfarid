<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس للمفاتيح الأجنبية في المسارات الساخنة (H7):
 * ‏foreignId()->constrained() على PostgreSQL ينشئ قيد FK فقط بلا فهرس،
 * فبدون هذه الفهارس تتحول قوائم الطلبات والسلة والحذف المتسلسل إلى مسوحات كاملة.
 * (في الإنتاج على جداول ضخمة تُنشأ بـ CREATE INDEX CONCURRENTLY خارج أي transaction.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', fn (Blueprint $table) => $table->index('customer_id'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('customer_id');
            $table->index('allocated_branch_id');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id');
            $table->index('product_variant_id');
            $table->index('allocated_branch_id');
        });
        Schema::table('shipments', fn (Blueprint $table) => $table->index('order_id'));
        Schema::table('coupon_usages', fn (Blueprint $table) => $table->index('order_id'));
    }

    public function down(): void
    {
        Schema::table('carts', fn (Blueprint $table) => $table->dropIndex(['customer_id']));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['allocated_branch_id']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['product_variant_id']);
            $table->dropIndex(['allocated_branch_id']);
        });
        Schema::table('shipments', fn (Blueprint $table) => $table->dropIndex(['order_id']));
        Schema::table('coupon_usages', fn (Blueprint $table) => $table->dropIndex(['order_id']));
    }
};
