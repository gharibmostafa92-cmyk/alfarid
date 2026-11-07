<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates staff users with role scoping, password generation, and cross-channel uniqueness checks.
 */
final class CreateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /**
     * Create a new staff user and assign validated roles.
     *
     * @param  User  $actor  Staff user initiating the creation.
     * @param  array<string, mixed>  $validated  Validated user attributes and roles.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @param  bool  $generatePassword  Whether to generate a random 16-character password.
     * @return array{user: User, plain_password: string|null}
     *
     * @throws ValidationException If email conflict occurs.
     */
    public function __invoke(User $actor, array $validated, array $via = [], bool $generatePassword = false): array
    {
        $roles = $validated['roles'] ?? [];
        $branch = $this->branches->restrictedBranch($actor);
        abort_if($branch !== null && (int) ($validated['branch_id'] ?? 0) !== $branch, 403);

        // Disallow assigning staff to a deactivated branch to prevent orphaned, inaccessible accounts.
        $targetBranchId = (int) ($validated['branch_id'] ?? 0);
        if ($targetBranchId > 0) {
            abort_unless((bool) (Branch::query()->find($targetBranchId)?->is_active), 422, 'لا يمكن تعيين موظف على فرع متوقف.');
        }
        if (in_array(StaffRole::SuperAdmin->value, $roles, true) && ! $actor->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            abort(403, 'يجب أن تكون Super Admin لإسناد هذا الدور.');
        }
        abort_unless(StaffAdministration::mayManageUser($actor, roles: $roles), 403, 'لا يمكنك إدارة حساب أو إسناد دور يمتلك صلاحيات لا تملكها.');
        $plain = null;
        $attrs = Arr::except($validated, ['roles']);
        $attrs['is_active'] ??= true;
        if ($generatePassword) {
            $plain = Str::password(16);
            $attrs['password'] = $plain;

            // Generated temporary credentials must be rotated upon first login.
            $attrs['must_change_password'] = true;
        }
        $attrs['password'] = Hash::make($attrs['password']);
        $user = DB::transaction(function () use ($attrs, $roles, $actor, $via): User {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['users.create', 'users.manage']);
            $branch = $this->branches->restrictedBranch($actor);
            abort_if($branch !== null && (int) ($attrs['branch_id'] ?? 0) !== $branch, 403);
            StaffAdministration::lockRoles($roles);
            abort_unless(StaffAdministration::mayManageUser($actor, roles: $roles), 403);

            // Re-run email uniqueness validation under lock to guard against concurrent creations.
            Validator::make(
                ['email' => $attrs['email'] ?? null],
                ['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email'), new EmailNotUsedByOtherAccountType('users')]]
            )->validate();
            try {
                $user = User::create($attrs);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'email' => 'هذا البريد مستخدم بحساب آخر في النظام.',
                ]);
            }
            $user->syncRoles($roles);
            StaffAdministration::audit($actor, 'user.created', 'user', $user->id, array_keys($attrs + ['roles' => $roles]), [], StaffAdministration::userState($user), $via);

            return $user;
        });

        return ['user' => $user, 'plain_password' => $plain];
    }
}
