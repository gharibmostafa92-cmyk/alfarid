<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order domain maintenance:
 * - Drops redundant non-unique index on carts.customer_id superseded by the unique constraint.
 * - Adds composite uniqueness on coupon_usages(coupon_id, order_id) to prevent duplicate recordings.
 * - Cascades deletion on cart_items.product_variant_id so abandoned carts do not block variant deletions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Redundant index superseded by carts_customer_id_unique
        Schema::table('carts', fn (Blueprint $table) => $table->dropIndex(['customer_id']));

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->index('product_variant_id');
        });

        // Deduplicate existing usage rows prior to enforcing composite unique constraint
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
