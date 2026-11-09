<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Procurement purchase order lifecycle:
 * draft -> sent_to_supplier -> supplier_accepted (or change_requested then re-sent)
 * -> partially_received -> completed, or cancelled prior to receipt.
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
