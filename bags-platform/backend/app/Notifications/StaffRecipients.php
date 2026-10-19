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
            ->where('is_active', true)
            ->where(function ($query) use ($permissions): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'Super Admin'));
                foreach ($permissions as $permission) {
                    $query->orWhereHas('permissions', fn ($perm) => $perm->where('name', $permission));
                }
            })
            ->get();
    }

    /** مديرو فرع معين: نشطون مقيدون بالفرع ويملكون صلاحية المخزون أو Super Admin. */
    public static function branchManagers(int $branchId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('branch_id', $branchId)
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'Super Admin'))
                    ->orWhereHas('permissions', fn ($perm) => $perm->where('name', 'inventory.view'));
            })
            ->get();
    }
}
