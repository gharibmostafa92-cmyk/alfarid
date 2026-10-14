<?php

declare(strict_types=1);

namespace App\Enums;

enum LocationType: string
{
    case Shelf = 'shelf';
    case Zone = 'zone';
    case Bin = 'bin';
    case Damaged = 'damaged';
    case Receiving = 'receiving';
}
