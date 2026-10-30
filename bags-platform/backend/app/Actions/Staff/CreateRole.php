<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class CreateRole
{
    public function __construct(private BranchAccess $access) {}

    /** @param array{name:string,permissions?:list<string>} $data @param array<string,mixed> $via */
    public function __invoke(User $actor, array $data, array $via = []): Role
    {
        if (StaffRole::isReserved($data['name'])) {
            abort(403, 'هذا الاسم محجوز لدور أساسي في النظام.');
        }
        $permissions = $data['permissions'] ?? [];
        if (! StaffAdministration::mayGrantPermissions($actor, $permissions)) {
            abort(403, 'لا يمكنك إسناد صلاحيات لا تملكها.');
        }

        return DB::transaction(function () use ($actor, $data, $permissions, $via): Role {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['roles.create', 'roles.manage']);
            $this->access->assertCentralAccess($actor);
            abort_unless(StaffAdministration::mayGrantPermissions($actor, $permissions), 403);
            StaffAdministration::clearPermissionCacheAfterCommit();
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'staff']);
            $role->syncPermissions($permissions);
            StaffAdministration::audit($actor, 'role.created', 'role', $role->id, array_keys($data), [], StaffAdministration::roleState($role), $via);

            return $role;
        });
    }
}
