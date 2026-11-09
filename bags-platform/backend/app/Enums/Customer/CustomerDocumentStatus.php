<?php

declare(strict_types=1);

namespace App\Enums\Customer;

enum CustomerDocumentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
