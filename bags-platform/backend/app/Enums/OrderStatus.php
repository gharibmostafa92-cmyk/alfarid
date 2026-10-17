<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * حالات الطلب — المصدر الوحيد للحقيقة؛ أي تغيير حالة يمر عبر
 * OrderService::changeStatus() التي تسجل التغيير في order_status_histories.
 */
enum OrderStatus: string
{
    case Pending = 'pending';         // قيد المراجعة
    case Confirmed = 'confirmed';     // مؤكد — هنا يتم الحجز والتوزيع على الفروع
    case Processing = 'processing';   // قيد التجهيز بالفرع
    case Shipped = 'shipped';         // تم الشحن
    case Delivered = 'delivered';     // تم التسليم
    case Cancelled = 'cancelled';     // ملغي
    case Returned = 'returned';       // مرتجع
}
