<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Services\Inventory\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves staff notification recipients based on active status,
 * permissions, branch assignment, and Super Admin privileges.
 */
final class StaffRecipients
{
    /**
     * @param list<string> $permissions
     * @param ?list<int> $branchIds null = every branch; [] = central-only
     * (events without branch-scoped data, e.g. unallocated store orders).
     */
    public static function withAnyPermission(array $permissions, ?array $branchIds = null): Collection
    {
        return User::query()
            ->where('is_active', true)->where('is_system', false)
            // Eager-load everything BranchAccess::restrictedBranch touches
            // (roles, direct permissions, permissions via roles) so the
            // per-user filter below cannot fan out into N+1 queries.
            ->with(['roles.permissions', 'permissions'])
            ->where(function ($query) use ($permissions): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'Super Admin')->where('guard_name', 'staff'));
                foreach ($permissions as $permission) {
                    $query->orWhereHas('permissions', fn ($perm) => $perm->where('name', $permission)->where('guard_name', 'staff'))
                        ->orWhereHas('roles', fn ($role) => $role->where('guard_name', 'staff')
                            ->whereHas('permissions', fn ($perm) => $perm->where('name', $permission)->where('guard_name', 'staff')));
                }
            })
            ->get()->filter(function (User $user) use ($branchIds): bool {
                // null means an event without branch-scoped business data.
                if ($branchIds === null) {
                    return true;
                }
                try {
                    $branch = app(BranchAccess::class)->restrictedBranch($user);

                    return $branch === null || in_array($branch, $branchIds, true);
                } catch (AuthorizationException) {
                    return false;
                }
            })->values();
    }

    /** Active branch managers assigned to the specified branch with inventory permissions or Super Admin role. */
    public static function branchManagers(int $branchId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('is_system', false)
            ->where('branch_id', $branchId)
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'Super Admin')->where('guard_name', 'staff'))
                    ->orWhereHas('permissions', fn ($perm) => $perm->where('name', 'inventory.view')->where('guard_name', 'staff'))
                    ->orWhereHas('roles', fn ($role) => $role->where('guard_name', 'staff')
                        ->whereHas('permissions', fn ($perm) => $perm->where('name', 'inventory.view')->where('guard_name', 'staff')));
            })
            ->get();
    }
}
