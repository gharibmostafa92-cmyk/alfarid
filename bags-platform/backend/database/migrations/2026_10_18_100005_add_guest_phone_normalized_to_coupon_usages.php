<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * هاتف الزائر الموحد محفوظًا على سجل الاستخدام نفسه (F-04): فحص حد
 * الكوبون للزائر يصير count() مفهرسًا بدل مسح كل الاستخدامات بذاكرة PHP
 * أثناء إمساك قفل صف الكوبون. البيانات القديمة تُملأ من هاتف الطلب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->string('guest_phone_normalized', 32)->nullable();
            $table->index(['coupon_id', 'guest_phone_normalized']);
        });

        DB::table('coupon_usages')
            ->join('orders', 'orders.id', '=', 'coupon_usages.order_id')
            ->whereNull('coupon_usages.customer_id')
            ->whereNotNull('orders.guest_phone')
            ->orderBy('coupon_usages.id')
            ->select('coupon_usages.id as usage_id', 'orders.guest_phone')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('coupon_usages')->where('id', $row->usage_id)
                        ->update(['guest_phone_normalized' => Customer::normalizePhone((string) $row->guest_phone)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropIndex(['coupon_id', 'guest_phone_normalized']);
            $table->dropColumn('guest_phone_normalized');
        });
    }
};
