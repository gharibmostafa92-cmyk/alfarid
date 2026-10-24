<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * حلّ مستلمي إشعارات الطاقم: مستخدمون نشطون يملكون أيًا من الصلاحيات
 * المعطاة، إضافة إلى حاملي دور Super Admin دائمًا.
 */
final class StaffRecipients
{
    /** @param list<string> $permissions */
    public static function withAnyPermission(array $permissions): Collection
    {
        return User::query()
            ->where('is_active', true)->where('is_system', false)
            ->where(function ($query) use ($permissions): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'Super Admin')->where('guard_name', 'staff'));
                foreach ($permissions as $permission) {
                    $query->orWhereHas('permissions', fn ($perm) => $perm->where('name', $permission)->where('guard_name', 'staff'))
                        ->orWhereHas('roles', fn ($role) => $role->where('guard_name', 'staff')
                            ->whereHas('permissions', fn ($perm) => $perm->where('name', $permission)->where('guard_name', 'staff')));
                }
            })
            ->get();
    }

    /** مديرو فرع معين: نشطون مقيدون بالفرع ويملكون صلاحية المخزون أو Super Admin. */
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
