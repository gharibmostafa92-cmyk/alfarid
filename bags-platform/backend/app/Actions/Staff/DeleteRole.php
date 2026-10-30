<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class DeleteRole
{
    public function __construct(private BranchAccess $access) {}

    /** @param array<string,mixed> $via */
    public function __invoke(User $actor, Role $role, array $via = []): string
    {
        abort_unless($role->guard_name === 'staff', 404);

        return DB::transaction(function () use ($actor, $role, $via): string {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['roles.delete', 'roles.manage']);
            $this->access->assertCentralAccess($actor);
            $locked = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->guard_name === 'staff', 404);
            if (StaffRole::isReserved($locked->name)) {
                return 'protected';
            }
            abort_unless(StaffAdministration::mayGrantPermissions($actor, $locked->permissions()->pluck('name')->all()), 403);
            if ($locked->users()->exists()) {
                return 'in_use';
            }
            StaffAdministration::audit($actor, 'role.deleted', 'role', $locked->id, ['name', 'permissions'], StaffAdministration::roleState($locked), [], $via);
            $locked->delete();
            StaffAdministration::clearPermissionCacheAfterCommit();

            return 'deleted';
        });
    }
}
