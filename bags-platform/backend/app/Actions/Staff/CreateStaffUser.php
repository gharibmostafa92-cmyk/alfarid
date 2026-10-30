<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\Branch;
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
        // M4: منع تعيين موظف جديد على فرع معطّل.
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
            // L8: المؤقتة المولّدة يجب تدويرها في أول دخول.
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
            // M-14: الفحص عند الاقتراح قد يسبق التأكيد بدقائق — أعد تشغيل
            // قاعدة تفرد البريد (داخل users وعبر أنواع الحسابات) تحت القفل.
            \Illuminate\Support\Facades\Validator::make(
                ['email' => $attrs['email'] ?? null],
                ['email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email'), new \App\Rules\EmailNotUsedByOtherAccountType('users')]]
            )->validate();
            $user = User::create($attrs);
            $user->syncRoles($roles);
            StaffAdministration::audit($actor, 'user.created', 'user', $user->id, array_keys($attrs + ['roles' => $roles]), [], StaffAdministration::userState($user), $via);

            return $user;
        });

        return ['user' => $user, 'plain_password' => $plain];
    }
}
