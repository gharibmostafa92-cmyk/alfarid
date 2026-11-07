<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\Ai\WhatsApp\WhatsAppIdentityService;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Updates staff user profile, roles, credentials, and associated security tokens.
 */
/**
 * Updates staff member credentials, branch assignments, and roles with optimistic locking.
 */
final class UpdateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /**
     * Update target staff user attributes, credentials, or assigned roles.
     *
     * @param  User  $actor  Staff user initiating the update.
     * @param  User  $target  Target staff user being modified.
     * @param  array<string, mixed>  $validated  Validated user attributes and roles.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @param  string|null  $verifiedPasswordHash  Pre-verified password hash for self-updates.
     * @param  bool  $selfRotation  Whether this is a self-rotation clearing must_change_password.
     * @return string|null Error status key on business rule rejection, or null on success.
     *
     * @throws ValidationException If password verification fails or email collision occurs.
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
            $originalPhone = (string) ($lockedUser->phone ?? '');
            $ownCredentialsChanged = $currentUser->is($lockedUser) && (isset($userAttributes['password'])
                || (isset($userAttributes['email']) && $userAttributes['email'] !== $lockedUser->email));
            if ($ownCredentialsChanged && ($verifiedPasswordHash === null
                || ! hash_equals($lockedUser->password, $verifiedPasswordHash))) {
                throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية الصحيحة لتغيير بيانات الدخول.']);
            }
            if (array_key_exists('branch_id', $userAttributes)) {
                $branch = $this->branches->restrictedBranch($currentUser);
                abort_if($branch !== null && (int) ($userAttributes['branch_id'] ?? 0) !== $branch, 403);

                // Reassigning staff to a deactivated branch is disallowed to prevent locking the user out.
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

            // Re-validate email uniqueness under lock to prevent unique index collisions from concurrent edits.
            if (isset($userAttributes['email']) && $userAttributes['email'] !== $lockedUser->email) {
                Validator::make(
                    ['email' => $userAttributes['email']],
                    ['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($lockedUser->getKey()), new EmailNotUsedByOtherAccountType('users')]]
                )->validate();
            }
            $emailChanged = isset($userAttributes['email']) && $userAttributes['email'] !== $lockedUser->email;
            $passwordChanged = array_key_exists('password', $userAttributes);
            if ($passwordChanged) {
                // Self-rotation clears the reset requirement, while admin resets mandate rotation on next login.
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

            $phoneChanged = array_key_exists('phone', $userAttributes)
                && (string) ($lockedUser->phone ?? '') !== $originalPhone;
            $lockedUser->unsetRelation('roles');
            $lockedUser->unsetRelation('permissions');
            $whatsappAllowed = false;
            try {
                $whatsappAllowed = $lockedUser->can('ai_agent.whatsapp');
            } catch (PermissionDoesNotExist) {
                $whatsappAllowed = false;
            }
            if ($phoneChanged || ! $lockedUser->is_active || ! $whatsappAllowed) {
                app(WhatsAppIdentityService::class)->revoke($lockedUser);
            }

            if ($passwordChanged || $emailChanged || $deactivated || $rolesChanged) {
                // Self password rotation keeps the current session alive so the
                // user is not logged out right after clearing must_change_password.
                $keepTokenId = $selfRotation ? ($via['current_token_id'] ?? null) : null;
                if ($keepTokenId !== null) {
                    $lockedUser->tokens()->whereKeyNot($keepTokenId)->delete();
                } else {
                    $lockedUser->tokens()->delete();
                }
            }

            return null;
        });
    }
}
