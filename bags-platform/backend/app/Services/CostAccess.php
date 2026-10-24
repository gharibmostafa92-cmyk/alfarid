<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/** Both legacy permission names represent the same confidential unit cost. */
final class CostAccess
{
    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->can('products.view_cost') || $user->can('inventory.view_cost'));
    }
}
