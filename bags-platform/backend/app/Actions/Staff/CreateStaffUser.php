<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CreateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /** @param array<string,mixed> $validated @param array<string,mixed> $via @return array{user:User,plain_password:?string} */
    public function __invoke(User $actor, array $validated, array $via = [], bool $generatePassword = false): array
    {
        $roles = $validated['roles'] ?? [];
        $branch = $this->branches->restrictedBranch($actor);
        abort_if($branch !== null && (int) ($validated['branch_id'] ?? 0) !== $branch, 403);
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
        }
        $attrs['password'] = Hash::make($attrs['password']);
        $user = DB::transaction(function () use ($attrs, $roles, $actor, $via): User {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['users.create', 'users.manage']);
            $branch = $this->branches->restrictedBranch($actor);
            abort_if($branch !== null && (int) ($attrs['branch_id'] ?? 0) !== $branch, 403);
            StaffAdministration::lockRoles($roles);
            abort_unless(StaffAdministration::mayManageUser($actor, roles: $roles), 403);
            $user = User::create($attrs);
            $user->syncRoles($roles);
            StaffAdministration::audit($actor, 'user.created', 'user', $user->id, array_keys($attrs + ['roles' => $roles]), [], StaffAdministration::userState($user), $via);

            return $user;
        });

        return ['user' => $user, 'plain_password' => $plain];
    }
}
