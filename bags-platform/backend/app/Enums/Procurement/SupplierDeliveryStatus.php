<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Advanced Shipping Notice (ASN) delivery inspection statuses.
 */
enum SupplierDeliveryStatus: string
{
    case PendingReceipt = 'pending_receipt';
    case Received = 'received';
    case Rejected = 'rejected';
}
