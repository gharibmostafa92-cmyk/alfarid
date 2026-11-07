<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Internal purchase requisition lifecycle statuses from branch request to purchase order conversion.
 */
enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ConvertedToPo = 'converted_to_po';
}
