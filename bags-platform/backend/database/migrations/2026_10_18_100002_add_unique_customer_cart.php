<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces one cart per authenticated customer while permitting multiple null customer_id guest carts.
 * Merges legacy duplicate customer carts before applying the unique index constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Consolidate duplicate carts before applying unique index to avoid constraint violation
        $this->mergeDuplicateCustomerCarts();

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique('customer_id');
        });
    }

    private function mergeDuplicateCustomerCarts(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1) Consolidate items from duplicate carts into the keeper cart
        DB::statement(<<<'SQL'
            WITH keepers AS (
                SELECT customer_id, MIN(id) AS keep_id
                FROM carts
                WHERE customer_id IS NOT NULL
                GROUP BY customer_id
                HAVING COUNT(*) > 1
            )
            INSERT INTO cart_items (cart_id, product_variant_id, qty, created_at, updated_at)
            SELECT k.keep_id, ci.product_variant_id, SUM(ci.qty), NOW(), NOW()
            FROM cart_items ci
            JOIN carts c ON c.id = ci.cart_id
            JOIN keepers k ON k.customer_id = c.customer_id
            WHERE c.id <> k.keep_id
            GROUP BY k.keep_id, ci.product_variant_id
            ON CONFLICT (cart_id, product_variant_id)
            DO UPDATE SET qty = cart_items.qty + EXCLUDED.qty, updated_at = NOW()
            SQL);

        // 2) Carry over applied coupon to keeper cart if currently empty
        DB::statement(<<<'SQL'
            WITH keepers AS (
                SELECT customer_id, MIN(id) AS keep_id
                FROM carts
                WHERE customer_id IS NOT NULL
                GROUP BY customer_id
                HAVING COUNT(*) > 1
            )
            UPDATE carts k
            SET coupon_id = sub.coupon_id
            FROM keepers kk
            JOIN LATERAL (
                SELECT c2.coupon_id
                FROM carts c2
                WHERE c2.customer_id = kk.customer_id AND c2.coupon_id IS NOT NULL
                ORDER BY c2.id
                LIMIT 1
            ) sub ON TRUE
            WHERE k.id = kk.keep_id AND k.coupon_id IS NULL
            SQL);

        // 3) Delete redundant duplicate cart records
        DB::statement(<<<'SQL'
            WITH keepers AS (
                SELECT customer_id, MIN(id) AS keep_id
                FROM carts
                WHERE customer_id IS NOT NULL
                GROUP BY customer_id
                HAVING COUNT(*) > 1
            )
            DELETE FROM carts c
            USING keepers k
            WHERE c.customer_id = k.customer_id AND c.id <> k.keep_id
            SQL);
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['customer_id']);
        });
    }
};
