<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Authorizes access to confidential product and inventory unit cost values.
 * Both legacy permissions represent the same commercial data boundary.
 */
final class CostAccess
{
    /**
     * Check if the authenticated staff user has permission to view unit costs.
     */
    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->can('products.view_cost') || $user->can('inventory.view_cost'));
    }
}
