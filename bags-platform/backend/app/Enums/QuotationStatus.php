<?php

declare(strict_types=1);

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case PricedByAdmin = 'priced_by_admin';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case ConvertedToOrder = 'converted_to_order';
}
