<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
}
