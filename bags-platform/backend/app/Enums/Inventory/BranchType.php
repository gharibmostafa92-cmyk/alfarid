<?php

declare(strict_types=1);

namespace App\Enums\Inventory;

enum BranchType: string
{
    case Branch = 'branch';
    case Warehouse = 'warehouse';
}
