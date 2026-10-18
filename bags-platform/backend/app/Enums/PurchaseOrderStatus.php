<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * حالات أمر الشراء الموجه للمورد:
 * draft ← sent_to_supplier ← supplier_accepted (أو change_requested ثم إعادة إرسال)
 * ← partially_received ← completed، وcancelled من أي حالة قبل الاستلام.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case SentToSupplier = 'sent_to_supplier';
    case SupplierAccepted = 'supplier_accepted';
    case ChangeRequested = 'change_requested';
    case PartiallyReceived = 'partially_received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
