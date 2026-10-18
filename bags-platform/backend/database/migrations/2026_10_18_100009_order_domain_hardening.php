<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحصين نطاق الطلبات:
 * - مفتاح منع التكرار مقيّد بهوية المُرسِل (F2): القيد الفريد العالمي على
 *   idempotency_key يسمح لطرف بسرقة/تعطيل مفتاح غيره؛ يُستبدل بقيد مركّب
 *   (idempotency_scope, idempotency_key).
 * - orders.guest_phone_normalized: هوية الزائر الموحّدة لسقف الطلبات (F5).
 * - coupon_usages.coupon_id: restrict بدل cascade حتى لا يمحو حذف الكوبون
 *   سجل استخدامه التاريخي (F14).
 * - فهرس orders(coupon_id, status) لعدّ الطلبات المعلقة للكوبون (F16).
 * - تعطيل مستخدم النظام فلا يُسجَّل دخوله (F23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'idempotency_scope')) {
                $table->string('idempotency_scope', 191)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('orders', 'guest_phone_normalized')) {
                $table->string('guest_phone_normalized', 32)->nullable()->after('guest_phone');
            }
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(['idempotency_scope', 'idempotency_key']);
            $table->index('guest_phone_normalized');
            $table->index(['coupon_id', 'status']);
        });

        // حذف الكوبون لا يمحو سجل استخدامه (F14)
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->foreign('coupon_id')->references('id')->on('coupons')->restrictOnDelete();
        });

        // مستخدم النظام للتدقيق فقط: لا يُسجَّل دخوله (F23)
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->update(['is_active' => true]);

        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_scope', 'idempotency_key']);
            $table->dropIndex(['guest_phone_normalized']);
            $table->dropIndex(['coupon_id', 'status']);
            $table->dropColumn('idempotency_scope');
            $table->dropColumn('guest_phone_normalized');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('idempotency_key');
        });
    }
};
