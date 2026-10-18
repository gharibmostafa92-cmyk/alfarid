<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * مستخدم نظام مخصص (M19): حركات المخزون التلقائية (تأكيد/شحن عبر الويب هوك،
 * إلغاء العميل لطلبه) تُنسب إليه بدل أول Super Admin — سجل تدقيق صادق.
 * كلمة مروره عشوائية غير معلنة ولا يُستخدم لتسجيل الدخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        // البريد من الإعداد لا نصًا ثابتًا (F-27): تغيير ORDER_SYSTEM_USER_EMAIL
        // يجب أن يوافق المستخدم الذي يبحث عنه OrderService وحركات النظام
        $email = (string) config('orders.system_user_email', 'system@alfarid.local');

        DB::table('users')->insertOrIgnore([
            'name' => 'System',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            // معطّل: يُستخدم للتدقيق فقط ولا يُسجَّل دخوله (F23)
            'is_active' => false,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->delete();
    }
};
