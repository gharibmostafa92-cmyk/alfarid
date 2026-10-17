<?php

declare(strict_types=1);

return [
    /*
     * حساب الموظف المنسوبة إليه حركات المخزون التلقائية (M19):
     * تأكيد الطلبات المدفوعة عبر الويب هوك وحركات إلغاء/إرجاع العملاء.
     * يُنشأ بحساب مخصص (هجرة إنشاء مستخدم النظام) بدل أول Super Admin.
     */
    'system_user_email' => env('ORDER_SYSTEM_USER_EMAIL', 'system@alfarid.local'),
];
