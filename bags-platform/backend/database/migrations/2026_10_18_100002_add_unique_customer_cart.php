<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سلة واحدة لكل عميل مسجل (M6): بلا هذا القيد، ‏firstOrCreate تحت التزامن
 * ينشئ سلتين للعميل وتتوزع البنود بينهما. ‏NULLs متعددة مسموحة (سلال الزوار).
 * ملحوظة إنتاج: تُدمج أي سلال مكررة موجودة قبل تطبيق القيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // دمج السلال المكررة قبل القيد (F13): على بيانات إنتاج فيها أكثر من
        // سلة لعميل، إضافة القيد بلا دمج تفشل. البنود تُجمع والكوبون يُنقل
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

        // 1) تجميع بنود السلال المكررة في السلة المُبقاة بلا تكرار صف
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

        // 2) نقل كوبون مكرر إلى السلة المُبقاة إن كانت بلا كوبون
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

        // 3) حذف السلال المكررة (بنودها انتقلت)
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
