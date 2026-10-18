<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * معرّف الطلب لدى بوابة الدفع (C1): يُخزَّن عند إنشاء جلسة الدفع من
 * الحقل الموقّع في الويب هوك (مثل Paymob: obj.order.id) وليس من رقم
 * التاجر غير الموقّع (merchant_order_id). ربط ويب هوك الدفع بالطلب عبر
 * هذا العمود يمنع توجيه دفعة صغيرة لطلب غالٍ بحقول غير موقّعة.
 * بلا FK: معرّف خارجي نصي، وليس مفتاحًا لجدول داخلي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('gateway_order_id', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['gateway_order_id']);
            $table->dropColumn('gateway_order_id');
        });
    }
};
