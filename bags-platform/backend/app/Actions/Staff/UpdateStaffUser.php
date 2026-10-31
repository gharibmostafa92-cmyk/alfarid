<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /**
     * Credentials are validated and hashed by the REST caller; AI may never supply them.
     *
     * L8: $selfRotation allows a password-only self change (verified current
     * password) without users.update — otherwise a freshly created staff
     * member could never clear must_change_password.
     */
    public function __invoke(User $actor, User $target, array $validated, array $via = [], ?string $verifiedPasswordHash = null, bool $selfRotation = false): ?string
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
            $verifiedPasswordHash, $via, $selfRotation,
        ): ?string {
            StaffAdministration::lock();
            if (! $selfRotation) {
                StaffAdministration::authorize($currentUser, ['users.update', 'users.manage']);
            } else {
                // Rotation-only: actor must be active and operating on self.
                abort_unless($currentUser->is_active && ! $currentUser->is_system && $currentUser->is($user), 403);
                // Rotation-only payloads change nothing but the password.
                abort_if($roles !== null || array_diff(array_keys($userAttributes), ['password', 'must_change_password']) !== [], 403);
            }
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
                // M4: نقل موظف لفرع معطّل مرفوض.
                $newBranchId = (int) ($userAttributes['branch_id'] ?? 0);
                if ($newBranchId > 0 && $newBranchId !== (int) $lockedUser->branch_id) {
                    abort_unless((bool) (Branch::query()->find($newBranchId)?->is_active), 422, 'لا يمكن تعيين موظف على فرع متوقف.');
                }
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
            if ($emailChanged) {
                Validator::make(
                    ['email' => $userAttributes['email']],
                    ['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($lockedUser->id), new EmailNotUsedByOtherAccountType('users')]]
                )->validate();
            }
            $passwordChanged = array_key_exists('password', $userAttributes);
            if ($passwordChanged) {
                // L8: تدوير ذاتي يُسقط الإجبار؛ تعيين إداري لكلمة سر موظف
                // آخر يُشغّله (لم يخترها بنفسه).
                $userAttributes['must_change_password'] = ! $currentUser->is($lockedUser);
            }
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
            if ($rolesChanged && ! $lockedUser->wasChanged('lock_version')) {
                // Roles live on the pivot table: a roles-only change issues no
                // users-row UPDATE, so TracksEditVersion never fires. Force the
                // bump to keep the optimistic-concurrency contract (version+1
                // per update) that the API and the AI expected_versions rely on.
                StaffAdministration::advanceVersion($lockedUser);
            }

            $deactivated = array_key_exists('is_active', $userAttributes)
                && ! (bool) $userAttributes['is_active'];

            StaffAdministration::audit($currentUser, 'user.updated', 'user', $lockedUser->id, array_keys($userAttributes + ($roles !== null ? ['roles' => $roles] : [])), $before, StaffAdministration::userState($lockedUser), $via);

            if ($passwordChanged || $emailChanged || $deactivated || $rolesChanged) {
                $lockedUser->tokens()->delete();
            }

            return null;
        });
    }
}
