<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\Auth\StaffAdministration;
use App\Services\Inventory\BranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Role;

/**
 * Creates a custom staff role and synchronizes its initial permission set.
 */
final class CreateRole
{
    public function __construct(private BranchAccess $access) {}

    /** @param array{name:string,permissions?:list<string>} $data @param array<string,mixed> $via */
    public function __invoke(User $actor, array $data, array $via = [], ?string $currentPassword = null): Role
    {
        if (StaffRole::isReserved($data['name'])) {
            abort(403, 'هذا الاسم محجوز لدور أساسي في النظام.');
        }
        $permissions = $data['permissions'] ?? [];
        if (! StaffAdministration::mayGrantPermissions($actor, $permissions)) {
            abort(403, 'لا يمكنك إسناد صلاحيات لا تملكها.');
        }
        // HIGH-01: verify the grant password before the transaction so no
        // bcrypt ever runs under the global staff advisory lock.
        [$preVerifiedHash, $currentPassword] = StaffAdministration::preVerifyGrantPassword($actor, $currentPassword, $permissions !== []);

        return DB::transaction(function () use ($actor, $data, $permissions, $via, $currentPassword, $preVerifiedHash): Role {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['roles.create', 'roles.manage']);
            $this->access->assertCentralAccess($actor);
            abort_unless(StaffAdministration::mayGrantPermissions($actor, $permissions), 403);
            if ($permissions !== []) {
                StaffAdministration::requireGrantPassword($actor, $currentPassword, $preVerifiedHash);
            }
            StaffAdministration::clearPermissionCacheAfterCommit();
            try {
                $role = Role::create(['name' => $data['name'], 'guard_name' => 'staff']);
            } catch (RoleAlreadyExists|UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'name' => 'اسم الدور مستخدم بالفعل؛ أعد التحميل ثم حاول مرة أخرى.',
                ]);
            }
            $role->syncPermissions($permissions);
            StaffAdministration::audit($actor, 'role.created', 'role', $role->id, array_keys($data), [], StaffAdministration::roleState($role), $via);

            return $role;
        });
    }
}
