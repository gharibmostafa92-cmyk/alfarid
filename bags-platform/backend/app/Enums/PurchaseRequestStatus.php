<?php

declare(strict_types=1);

namespace App\Enums;

/** حالات طلب الشراء الداخلي (من الفروع إلى إدارة المشتريات). */
enum PurchaseRequestStatus: string
{
    case Draft = 'draft';                     // مسودة قابلة للتعديل
    case Pending = 'pending';                 // بانتظار اعتماد مدير المشتريات
    case Approved = 'approved';               // معتمد — جاهز للتحويل لأمر شراء
    case Rejected = 'rejected';               // مرفوض
    case ConvertedToPo = 'converted_to_po';   // تحوّل إلى أمر شراء
}
