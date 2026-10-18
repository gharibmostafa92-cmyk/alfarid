<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فهارس بحث شاشة الطلبات (F-18):
 * - orders.order_number / orders.guest_phone ببحث البادئة LIKE 'x%':
 *   الفهرس الافتراضي btree لا يُستخدم إلا بمطابقة collation غير C،
 *   لذا ننشئ فهرس text_pattern_ops المخصص لعمليات البادئة.
 * - orders.guest_name ببحث الاحتواء LIKE '%x%': فهرس trigram (GIN).
 *
 * ملاحظة تشغيلية: الهجرات في PostgreSQL تعمل داخل معاملة، و
 * CREATE INDEX CONCURRENTLY لا يسمح بذلك. على الجداول الكبيرة في
 * الإنتاج يُعاد بناء هذه الفهارس لاحقًا بـ CONCURRENTLY بلا قفل كتابة:
 *   CREATE INDEX CONCURRENTLY ... ON orders (... text_pattern_ops);
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_order_number_pattern_index ON orders (order_number text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_guest_phone_pattern_index ON orders (guest_phone text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_guest_name_trgm_index ON orders USING GIN (guest_name gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS orders_order_number_pattern_index');
        DB::statement('DROP INDEX IF EXISTS orders_guest_phone_pattern_index');
        DB::statement('DROP INDEX IF EXISTS orders_guest_name_trgm_index');
    }
};
