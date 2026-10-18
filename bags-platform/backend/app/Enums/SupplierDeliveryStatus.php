<?php

declare(strict_types=1);

namespace App\Enums;

/** حالات إشعار توريد المورد (ASN): بانتظار استلام المخزن، أو مستلم، أو مرفوض. */
enum SupplierDeliveryStatus: string
{
    case PendingReceipt = 'pending_receipt';
    case Received = 'received';
    case Rejected = 'rejected';
}
