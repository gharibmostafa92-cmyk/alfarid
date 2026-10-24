<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StaffAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class UserController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}

    private function assertAccessible(?User $actor, User $target): void
    {
        abort_if($target->is_system, 404);
        $branch = $this->branches->restrictedBranch($actor);
        abort_if($branch !== null && (int) $target->branch_id !== $branch, 403);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
            'role' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'staff'),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branches->restrictedBranch($request->user('staff'));

        $users = User::query()
            ->with(['branch', 'roles'])
            ->where('is_system', false)
            ->when($branch !== null, fn ($query) => $query->where('branch_id', $branch))
            ->when(array_key_exists('branch_id', $filters), function ($query) use ($filters): void {
                if ($filters['branch_id'] === null) {
                    $query->whereNull('branch_id');

                    return;
                }

                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['role']), function ($query) use ($filters): void {
                $query->whereHas('roles', function ($roles) use ($filters): void {
                    $roles->where('name', $filters['role'])->where('guard_name', 'staff');
                });
            })
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $term = SearchTerm::pattern(mb_strtolower(trim($filters['search'])));
                $query->where(function ($q) use ($term): void {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $roles = $validated['roles'] ?? [];
        $currentUser = $request->user('staff');
        $branch = $this->branches->restrictedBranch($currentUser);
        abort_if($branch !== null && (int) ($validated['branch_id'] ?? 0) !== $branch, 403);

        if (
            in_array(StaffRole::SuperAdmin->value, $roles, true)
            && (! $currentUser instanceof User || ! $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff'))
        ) {
            return response()->json([
                'message' => 'يجب أن تكون Super Admin لإسناد هذا الدور.',
            ], 403);
        }

        if (! $this->mayManageUser($currentUser, roles: $roles)) {
            return $this->insufficientPermissionsResponse();
        }

        $userAttributes = Arr::except($validated, ['roles']);
        $userAttributes['is_active'] ??= true;
        $userAttributes['password'] = Hash::make($userAttributes['password']);

        $user = DB::transaction(function () use ($userAttributes, $roles, $currentUser): User {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['users.create', 'users.manage']);
            $branch = $this->branches->restrictedBranch($currentUser);
            abort_if($branch !== null && (int) ($userAttributes['branch_id'] ?? 0) !== $branch, 403);
            StaffAdministration::lockRoles($roles);
            abort_unless($this->mayManageUser($currentUser, roles: $roles), 403);
            $user = User::create($userAttributes);
            $user->syncRoles($roles);
            StaffAdministration::audit($currentUser, 'user.created', 'user', $user->id, array_keys($userAttributes + ['roles' => $roles]), after: StaffAdministration::userState($user));

            return $user->load(['branch', 'roles.permissions', 'permissions']);
        });

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->assertAccessible($request->user('staff'), $user);

        return UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        abort_if($user->is_system, 404);
        $validated = $request->validated();
        $currentUser = $request->user('staff');
        $isCurrentUser = $currentUser instanceof User && $currentUser->is($user);

        if ($isCurrentUser && (array_key_exists('roles', $validated) || array_key_exists('is_active', $validated))) {
            return response()->json([
                'message' => 'لا يمكنك تغيير أدوارك أو حالة تنشيط حسابك الحالي.',
            ], 403);
        }

        $roles = $validated['roles'] ?? null;
        $userAttributes = Arr::except($validated, ['roles', 'current_password', 'expected_version']);

        if (array_key_exists('password', $userAttributes) && $userAttributes['password'] === null) {
            unset($userAttributes['password']);
        }

        $verifiedPasswordHash = null;
        $credentialChange = $isCurrentUser && (isset($userAttributes['password'])
            || (isset($userAttributes['email']) && $userAttributes['email'] !== $user->email));
        $passwordLimitKey = 'staff:current-password:'.$user->id;
        if ($credentialChange && RateLimiter::tooManyAttempts($passwordLimitKey, max(1, (int) config('auth.staff_current_password_failures', 5)))) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($passwordLimitKey));
        }
        if ($isCurrentUser && (isset($userAttributes['password'])
            || (isset($userAttributes['email']) && $userAttributes['email'] !== $user->email))
            && is_string($validated['current_password'] ?? null)
            && Hash::check($validated['current_password'], $user->password)) {
            $verifiedPasswordHash = $user->password;
        }

        if ($credentialChange && is_string($validated['current_password'] ?? null)) {
            if ($verifiedPasswordHash === null) {
                RateLimiter::hit($passwordLimitKey, 3600);
            } else {
                RateLimiter::clear($passwordLimitKey);
            }
        }
        if ($credentialChange && $verifiedPasswordHash === null) {
            throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية الصحيحة لتغيير بيانات الدخول.']);
        }
        if (isset($userAttributes['password'])) {
            $userAttributes['password'] = Hash::make($userAttributes['password']);
        }

        $isSuperAdminRequester = $currentUser instanceof User
            && $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff');
        $assigningSuperAdmin = is_array($roles)
            && in_array(StaffRole::SuperAdmin->value, $roles, true);

        $failure = DB::transaction(function () use (
            $user,
            $userAttributes,
            $roles,
            $currentUser,
            $isSuperAdminRequester,
            $assigningSuperAdmin,
            $validated,
            $verifiedPasswordHash,
        ): ?string {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['users.update', 'users.manage']);
            $isSuperAdminRequester = $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff');
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->assertAccessible($currentUser, $lockedUser);
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

            if (! $this->mayManageUser($currentUser, $lockedUser, $roles ?? [])) {
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
            StaffAdministration::audit($currentUser, 'user.updated', 'user', $lockedUser->id, array_keys($userAttributes + ($roles !== null ? ['roles' => $roles] : [])), $before, StaffAdministration::userState($lockedUser));

            if ($passwordChanged || $emailChanged || $deactivated || $rolesChanged) {
                $lockedUser->tokens()->delete();
            }

            return null;
        });

        if ($failure !== null) {
            if ($failure === 'insufficient_permissions') {
                return $this->insufficientPermissionsResponse();
            }

            return response()->json([
                'message' => $failure === 'protected_super_admin'
                    ? 'يجب الإبقاء على حساب Super Admin نشط واحد على الأقل.'
                    : 'لا يمكن للمستخدم الحالي تعديل حساب Super Admin أو إسناد دوره.',
            ], 403);
        }

        return UserResource::make($user->refresh()->load(['branch', 'roles.permissions', 'permissions']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_system, 404);
        $currentUser = $request->user('staff');
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:0']]);

        if ($currentUser instanceof User && $currentUser->is($user)) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الحالي.',
            ], 403);
        }

        $deleted = DB::transaction(function () use ($currentUser, $user, $validated): bool {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['users.delete', 'users.manage']);
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->assertAccessible($currentUser, $lockedUser);
            if (($lockedUser->hasRole(StaffRole::SuperAdmin->value, 'staff')
                && (! $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff') || ! StaffAdministration::hasOtherActiveSuperAdmin($lockedUser)))
                || ! $this->mayManageUser($currentUser, $lockedUser)) {
                return false;
            }

            if (! $lockedUser->is_active) {
                return true;
            }
            StaffAdministration::assertVersion($lockedUser, (int) $validated['expected_version']);
            $before = StaffAdministration::userState($lockedUser);
            $lockedUser->forceFill(['is_active' => false]);
            StaffAdministration::advanceVersion($lockedUser);
            StaffAdministration::audit($currentUser, 'user.deactivated', 'user', $lockedUser->id, ['is_active'], $before, StaffAdministration::userState($lockedUser));
            $lockedUser->tokens()->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'لا يمكن تعطيل حساب Super Admin أو حساب يمتلك صلاحيات لا تملكها.',
            ], 403);
        }

        return response()->json([
            'message' => 'تم تعطيل حساب الموظف وإلغاء رموزه.',
            'data' => UserResource::make($user->refresh()->load(['branch', 'roles.permissions', 'permissions']))->resolve($request),
        ]);
    }

    /** @param list<string> $roles */
    private function mayManageUser(mixed $actor, ?User $target = null, array $roles = []): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if ($target?->is_system) {
            return false;
        }

        if ($actor->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            return true;
        }

        // Super Admin is privileged by Gate even when it has no explicit permissions.
        if (in_array(StaffRole::SuperAdmin->value, $roles, true)
            || $target?->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            return false;
        }

        $permissions = Role::query()
            ->where('guard_name', 'staff')
            ->whereIn('name', $roles)
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'));

        if ($target !== null) {
            $permissions = $permissions->merge($target->getAllPermissions()->pluck('name'));
        }

        return $actor->hasAllPermissions($permissions->unique()->all());
    }

    private function insufficientPermissionsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'لا يمكنك إدارة حساب أو إسناد دور يمتلك صلاحيات لا تملكها.',
        ], 403);
    }
}
