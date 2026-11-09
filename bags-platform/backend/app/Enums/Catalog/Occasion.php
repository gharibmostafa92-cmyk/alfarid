<?php

declare(strict_types=1);

namespace App\Enums\Catalog;

enum Occasion: string
{
    case Casual = 'casual';
    case Work = 'work';
    case Weekend = 'weekend';
    case Evening = 'evening';
}
