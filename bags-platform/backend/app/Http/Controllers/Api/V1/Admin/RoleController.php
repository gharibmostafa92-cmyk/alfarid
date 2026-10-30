<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Staff\CreateRole;
use App\Actions\Staff\DeleteRole;
use App\Actions\Staff\UpdateRole;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Admin\RoleResource;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

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

        $role = app(CreateRole::class)($currentUser, $validated);

        return $this->roleResource($role)->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->ensureStaffRole($role);

        return $this->roleResource($role);
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

        $failure = app(UpdateRole::class)($currentUser, $role, $validated);

        if ($failure !== null) {
            return response()->json([
                'message' => 'لا يمكن تعديل اسم دور أساسي أو تغيير دور Super Admin.',
            ], 403);
        }

        return $this->roleResource($role->refresh());
    }

    public function destroy(Request $request, Role $role): JsonResponse|Response
    {
        $this->ensureStaffRole($role);

        $result = app(DeleteRole::class)($request->user('staff'), $role);

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

    private function roleResource(Role $role): RoleResource
    {
        return RoleResource::make($role->loadMissing('permissions')->loadCount('users'));
    }
}
