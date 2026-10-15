<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
}
