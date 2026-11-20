<?php

declare(strict_types=1);

namespace App\Enums\Orders;

enum ReturnReasonType: string
{
    case ChangeOfMind = 'change_of_mind';
    case DefectOrMismatch = 'defect_or_mismatch';
}
