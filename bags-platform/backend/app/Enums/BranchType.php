<?php

declare(strict_types=1);

namespace App\Enums;

enum BranchType: string
{
    case Branch = 'branch';
    case Warehouse = 'warehouse';
}
