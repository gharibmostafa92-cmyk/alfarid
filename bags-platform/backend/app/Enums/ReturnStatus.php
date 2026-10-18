<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * حالات طلب المرتجع (RMA) — دورة الحياة:
 * pending ← بانتظار مراجعة الأدمن، approved ← موافقة مبدئية (بلا أي أثر مخزني)،
 * received ← استلام فعلي بعد الفحص (هنا فقط يتحرك المخزون)،
 * refunded ← اكتمل رد المبلغ، rejected/cancelled ← مغلق بلا آثار.
 */
enum ReturnStatus: string
{
    case Pending = 'pending';     // قيد المراجعة
    case Approved = 'approved';   // موافقة مبدئية — لا تضيف رصيدًا
    case Rejected = 'rejected';   // مرفوض من الأدمن
    case Received = 'received';   // استُلم وفُحص — حركات المخزون حدثت هنا
    case Refunded = 'refunded';   // اكتمل الاسترداد المالي
    case Cancelled = 'cancelled'; // ملغي
}
