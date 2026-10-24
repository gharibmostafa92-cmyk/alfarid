<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Admin\RoleResource;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $roles = Role::query()
            ->where('guard_name', 'staff')
            ->with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? [];
        $currentUser = $request->user('staff');

        if (StaffRole::isReserved($validated['name'])) {
            return response()->json([
                'message' => 'هذا الاسم محجوز لدور أساسي في النظام.',
            ], 403);
        }

        if (! $this->mayGrantPermissions($currentUser, $permissions)) {
            return response()->json([
                'message' => 'لا يمكنك إسناد صلاحيات لا تملكها.',
            ], 403);
        }

        $role = DB::transaction(function () use ($validated, $permissions, $currentUser): Role {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['roles.create', 'roles.manage']);
            app(BranchAccess::class)->assertCentralAccess($currentUser);
            abort_unless($this->mayGrantPermissions($currentUser, $permissions), 403);
            StaffAdministration::clearPermissionCacheAfterCommit();
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'staff',
            ]);
            $role->syncPermissions($permissions);
            StaffAdministration::audit($currentUser, 'role.created', 'role', $role->id, array_keys($validated), after: StaffAdministration::roleState($role));

            return $role->load('permissions')->loadCount('users');
        });

        return RoleResource::make($role)->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->ensureStaffRole($role);

        return RoleResource::make($role->load('permissions')->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource|JsonResponse
    {
        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? null;
        $currentUser = $request->user('staff');

        if (array_key_exists('name', $validated) && StaffRole::isReserved($validated['name'])) {
            return response()->json([
                'message' => 'هذا الاسم محجوز لدور أساسي في النظام.',
            ], 403);
        }

        if (is_array($permissions) && ! $this->mayGrantPermissions($currentUser, $permissions)) {
            return response()->json([
                'message' => 'لا يمكنك إسناد صلاحيات لا تملكها.',
            ], 403);
        }

        $failure = DB::transaction(function () use ($role, $validated, $permissions, $currentUser): ?string {
            StaffAdministration::lock();
            StaffAdministration::authorize($currentUser, ['roles.update', 'roles.manage']);
            app(BranchAccess::class)->assertCentralAccess($currentUser);
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureStaffRole($lockedRole);
            StaffAdministration::assertVersion($lockedRole, isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);
            $before = StaffAdministration::roleState($lockedRole);

            if (($lockedRole->name === StaffRole::SuperAdmin->value || (StaffRole::isReserved($lockedRole->name) && array_key_exists('name', $validated)))) {
                return 'protected';
            }

            abort_unless($this->mayGrantPermissions($currentUser, array_unique(array_merge(
                $lockedRole->permissions()->pluck('name')->all(), $permissions ?? []
            ))), 403);
            StaffAdministration::clearPermissionCacheAfterCommit();

            if (array_key_exists('name', $validated)) {
                $lockedRole->name = $validated['name'];
                $lockedRole->save();
            }

            if ($permissions !== null) {
                $lockedRole->syncPermissions($permissions);
            }
            StaffAdministration::advanceVersion($lockedRole);
            StaffAdministration::audit($currentUser, 'role.updated', 'role', $lockedRole->id, array_keys($validated), $before, StaffAdministration::roleState($lockedRole));

            return null;
        });

        if ($failure !== null) {
            return response()->json([
                'message' => 'لا يمكن تعديل اسم دور أساسي أو تغيير دور Super Admin.',
            ], 403);
        }

        return RoleResource::make($role->refresh()->load('permissions')->loadCount('users'));
    }

    public function destroy(Request $request, Role $role): JsonResponse|Response
    {
        $this->ensureStaffRole($role);

        $result = DB::transaction(function () use ($role, $request): string {
            StaffAdministration::lock();
            StaffAdministration::authorize($request->user('staff'), ['roles.delete', 'roles.manage']);
            app(BranchAccess::class)->assertCentralAccess($request->user('staff'));
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureStaffRole($lockedRole);

            if (StaffRole::isReserved($lockedRole->name)) {
                return 'protected';
            }

            abort_unless($this->mayGrantPermissions($request->user('staff'), $lockedRole->permissions()->pluck('name')->all()), 403);

            if ($lockedRole->users()->exists()) {
                return 'in_use';
            }

            StaffAdministration::audit($request->user('staff'), 'role.deleted', 'role', $lockedRole->id, ['name', 'permissions'], before: StaffAdministration::roleState($lockedRole));
            $lockedRole->delete();
            StaffAdministration::clearPermissionCacheAfterCommit();

            return 'deleted';
        });

        if ($result === 'protected') {
            return response()->json([
                'message' => 'لا يمكن حذف دور أساسي في النظام.',
            ], 403);
        }

        if ($result === 'in_use') {
            return response()->json([
                'message' => 'لا يمكن حذف دور مسند إلى موظفين. انقل الموظفين إلى دور آخر أولًا.',
            ], 409);
        }

        return response()->noContent();
    }

    private function mayGrantPermissions(mixed $user, array $permissions): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
            || $user->hasAllPermissions($permissions);
    }

    private function ensureStaffRole(Role $role): void
    {
        abort_unless($role->guard_name === 'staff', 404);
    }
}
