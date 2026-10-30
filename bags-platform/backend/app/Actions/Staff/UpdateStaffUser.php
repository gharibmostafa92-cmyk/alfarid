<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /** Credentials are validated and hashed by the REST caller; AI may never supply them. */
    public function __invoke(User $actor, User $target, array $validated, array $via = [], ?string $verifiedPasswordHash = null): ?string
    {
        abort_if($target->is_system, 404);
        if (($via['channel'] ?? null) === 'ai') {
            abort_if(array_intersect(array_keys($validated), ['email', 'password', 'current_password']) !== [] || $actor->is($target), 403);
        }
        abort_if($actor->is($target) && (array_key_exists('roles', $validated) || array_key_exists('is_active', $validated)), 403, 'لا يمكنك تغيير أدوارك أو حالة تنشيط حسابك الحالي.');
        $user = $target;
        $currentUser = $actor;
        $roles = $validated['roles'] ?? null;
        $userAttributes = Arr::except($validated, ['roles', 'current_password', 'expected_version']);
        $isSuperAdminRequester = $actor->hasRole(StaffRole::SuperAdmin->value, 'staff');
        $assigningSuperAdmin = is_array($roles) && in_array(StaffRole::SuperAdmin->value, $roles, true);

        return DB::transaction(function () use (
            $user,
            $userAttributes,
            $roles,
            $currentUser,
            $isSuperAdminRequester,
            $assigningSuperAdmin,
            $validated,
            $verifiedPasswordHash, $via,
        ): ?string {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['users.update', 'users.manage']);
            $isSuperAdminRequester = $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff');
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            abort_if($lockedUser->is_system, 404);
            $branch = $this->branches->restrictedBranch($currentUser);
            abort_if($branch !== null && (int) $lockedUser->branch_id !== $branch, 403);
            StaffAdministration::assertVersion($lockedUser, isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);
            $before = StaffAdministration::userState($lockedUser);
            $ownCredentialsChanged = $currentUser->is($lockedUser) && (isset($userAttributes['password'])
                || (isset($userAttributes['email']) && $userAttributes['email'] !== $lockedUser->email));
            if ($ownCredentialsChanged && ($verifiedPasswordHash === null
                || ! hash_equals($lockedUser->password, $verifiedPasswordHash))) {
                throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية الصحيحة لتغيير بيانات الدخول.']);
            }
            if (array_key_exists('branch_id', $userAttributes)) {
                $branch = $this->branches->restrictedBranch($currentUser);
                abort_if($branch !== null && (int) ($userAttributes['branch_id'] ?? 0) !== $branch, 403);
            }
            if ($roles !== null) {
                StaffAdministration::lockRoles($roles);
            }
            $targetIsSuperAdmin = $lockedUser->hasRole(StaffRole::SuperAdmin->value, 'staff');

            if ($targetIsSuperAdmin && (
                (array_key_exists('is_active', $userAttributes) && ! (bool) $userAttributes['is_active'])
                || ($roles !== null && ! in_array(StaffRole::SuperAdmin->value, $roles, true))
            )) {
                if (! $isSuperAdminRequester || ! StaffAdministration::hasOtherActiveSuperAdmin($lockedUser)) {
                    return 'protected_super_admin';
                }
            }

            if (! $isSuperAdminRequester && (
                $targetIsSuperAdmin || $assigningSuperAdmin
            )) {
                return 'protected';
            }

            if (! StaffAdministration::mayManageUser($currentUser, $lockedUser, $roles ?? [])) {
                return 'insufficient_permissions';
            }

            $emailChanged = isset($userAttributes['email']) && $userAttributes['email'] !== $lockedUser->email;
            $lockedUser->update($userAttributes);

            $rolesChanged = false;
            if ($roles !== null) {
                $requestedRoles = $roles;
                sort($requestedRoles);
                $currentRoles = $before['roles'];
                sort($currentRoles);
                $rolesChanged = $requestedRoles !== $currentRoles;
                if ($rolesChanged) {
                    $lockedUser->syncRoles($roles);
                }
            }

            $passwordChanged = array_key_exists('password', $userAttributes);
            $deactivated = array_key_exists('is_active', $userAttributes)
                && ! (bool) $userAttributes['is_active'];

            StaffAdministration::advanceVersion($lockedUser);
            StaffAdministration::audit($currentUser, 'user.updated', 'user', $lockedUser->id, array_keys($userAttributes + ($roles !== null ? ['roles' => $roles] : [])), $before, StaffAdministration::userState($lockedUser), $via);

            if ($passwordChanged || $emailChanged || $deactivated || $rolesChanged) {
                $lockedUser->tokens()->delete();
            }

            return null;
        });
    }
}
