<?php

declare(strict_types=1);

return [
    /*
     * حساب الموظف المنسوبة إليه حركات المخزون التلقائية (M19):
     * تأكيد الطلبات المدفوعة عبر الويب هوك وحركات إلغاء/إرجاع العملاء.
     * يُنشأ بحساب مخصص (هجرة إنشاء مستخدم النظام) بدل أول Super Admin.
     */
    'system_user_email' => env('ORDER_SYSTEM_USER_EMAIL', 'system@alfarid.local'),

    /*
     * انتهاء الطلبات المعلقة (F-01): الحجز الفوري عند الشراء يعني أن الطلب
     * المهجور يحبس مخزونًا — فيُلغى تلقائيًا (ويُفك حجزه) بعد هذه المدد.
     * الإلكتروني غير المدفوع: دقائق؛ والدفع عند الاستلام: ساعات يحددها النشاط.
     */
    // 3DS والمحافظ والتحويلات قد تتأخر أكثر من 30 دقيقة فيصل الدفع بعد الإلغاء
    // (M8): المهلة 90 دقيقة تعطيها مجالًا بلا استردادات ولا بيع مخزون محجوز
    'pending_online_expire_minutes' => (int) env('ORDER_PENDING_ONLINE_EXPIRE_MINUTES', 90),
    'pending_cod_expire_hours' => (int) env('ORDER_PENDING_COD_EXPIRE_HOURS', 72),
    // طلبات الزوار المؤكدة بالهاتف فقط أسرع انتهاءً (F5/H2): لا تحبس المخزون طويلًا بلا التزام
    'pending_guest_cod_expire_hours' => (int) env('ORDER_PENDING_GUEST_COD_EXPIRE_HOURS', 12),
];
