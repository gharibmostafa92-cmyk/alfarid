<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
