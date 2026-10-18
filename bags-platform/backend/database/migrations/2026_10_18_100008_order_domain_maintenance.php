<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صيانة نطاق الطلبات (F-29):
 * - سقوط فهرس carts.customer_id غير الفريد المكرر مع القيد الفريد اللاحق.
 * - قيد تفرد على coupon_usages(coupon_id, order_id) يمنع تسجيل الاستخدام مرتين.
 * - cart_items.product_variant_id: فهرس + حذف متسلسل حتى لا تمنع السلال
 *   المهجورة حذف المتغيرات.
 */
return new class extends Migration
{
    public function up(): void
    {
        // فهرس عادي مكرر مع carts_customer_id_unique (يُضيفه _100002)
        Schema::table('carts', fn (Blueprint $table) => $table->dropIndex(['customer_id']));

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->index('product_variant_id');
        });

        // إزالة أي تكرار قديم قبل فرض القيد (يبقي أصغر id لكل زوج)
        DB::statement('DELETE FROM coupon_usages a USING coupon_usages b
            WHERE a.id > b.id AND a.coupon_id = b.coupon_id AND a.order_id = b.order_id');
        Schema::table('coupon_usages', fn (Blueprint $table) => $table->unique(['coupon_id', 'order_id']));
    }

    public function down(): void
    {
        Schema::table('coupon_usages', fn (Blueprint $table) => $table->dropUnique(['coupon_id', 'order_id']));

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex(['product_variant_id']);
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreign('product_variant_id')->references('id')->on('product_variants');
        });

        Schema::table('carts', fn (Blueprint $table) => $table->index('customer_id'));
    }
};
