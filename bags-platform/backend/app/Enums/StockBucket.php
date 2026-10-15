<?php

declare(strict_types=1);

namespace App\Enums;

enum StockBucket: string
{
    case OnHand = 'on_hand';
    case Reserved = 'reserved';
    case Damaged = 'damaged';
    case InTransit = 'in_transit';
}
