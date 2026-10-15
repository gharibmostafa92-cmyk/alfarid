<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Admin\RoleResource;
use App\Models\User;
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

        if ($validated['name'] === StaffRole::SuperAdmin->value) {
            return response()->json([
                'message' => 'لا يمكن إنشاء دور Super Admin من هذه الواجهة.',
            ], 403);
        }

        if (! $this->mayGrantPermissions($currentUser, $permissions)) {
            return response()->json([
                'message' => 'لا يمكنك إسناد صلاحيات لا تملكها.',
            ], 403);
        }

        $role = DB::transaction(function () use ($validated, $permissions): Role {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'staff',
            ]);
            $role->syncPermissions($permissions);

            return $role->load('permissions');
        });

        return RoleResource::make($role)->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->ensureStaffRole($role);

        return RoleResource::make($role->load('permissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource|JsonResponse
    {
        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? null;
        $currentUser = $request->user('staff');

        if (array_key_exists('name', $validated) && $validated['name'] === StaffRole::SuperAdmin->value) {
            return response()->json([
                'message' => 'لا يمكن تسمية دور آخر باسم Super Admin.',
            ], 403);
        }

        if (is_array($permissions) && ! $this->mayGrantPermissions($currentUser, $permissions)) {
            return response()->json([
                'message' => 'لا يمكنك إسناد صلاحيات لا تملكها.',
            ], 403);
        }

        $failure = DB::transaction(function () use ($role, $validated, $permissions): ?string {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureStaffRole($lockedRole);

            if ($lockedRole->name === StaffRole::SuperAdmin->value) {
                return 'protected';
            }

            if (array_key_exists('name', $validated)) {
                $lockedRole->name = $validated['name'];
                $lockedRole->save();
            }

            if ($permissions !== null) {
                $lockedRole->syncPermissions($permissions);
            }

            return null;
        });

        if ($failure !== null) {
            return response()->json([
                'message' => 'لا يمكن تعديل دور Super Admin الأساسي.',
            ], 403);
        }

        return RoleResource::make($role->refresh()->load('permissions'));
    }

    public function destroy(Role $role): JsonResponse|Response
    {
        $this->ensureStaffRole($role);

        $result = DB::transaction(function () use ($role): string {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureStaffRole($lockedRole);

            if ($lockedRole->name === StaffRole::SuperAdmin->value) {
                return 'protected';
            }

            if ($lockedRole->users()->exists()) {
                return 'in_use';
            }

            $lockedRole->delete();

            return 'deleted';
        });

        if ($result === 'protected') {
            return response()->json([
                'message' => 'لا يمكن حذف دور Super Admin الأساسي.',
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

        return $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
            || $user->hasAllPermissions($permissions);
    }

    private function ensureStaffRole(Role $role): void
    {
        abort_unless($role->guard_name === 'staff', 404);
    }
}
