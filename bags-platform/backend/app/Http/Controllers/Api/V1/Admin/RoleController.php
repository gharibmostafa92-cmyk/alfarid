<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Admin\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

        $role = DB::transaction(function () use ($validated): Role {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'staff',
            ]);
            $role->syncPermissions($validated['permissions'] ?? []);

            return $role->load('permissions');
        });

        return RoleResource::make($role)->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->ensureStaffRole($role);

        return RoleResource::make($role->load('permissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->ensureStaffRole($role);
        abort_if(
            $role->name === StaffRole::SuperAdmin->value,
            403,
            'لا يمكن تعديل دور Super Admin الأساسي.',
        );
        $validated = $request->validated();

        DB::transaction(function () use ($role, $validated): void {
            if (array_key_exists('name', $validated)) {
                $role->name = $validated['name'];
                $role->save();
            }

            if (array_key_exists('permissions', $validated)) {
                $role->syncPermissions($validated['permissions']);
            }
        });

        return RoleResource::make($role->load('permissions'));
    }

    public function destroy(Role $role): JsonResponse|Response
    {
        $this->ensureStaffRole($role);

        if ($role->name === StaffRole::SuperAdmin->value) {
            return response()->json([
                'message' => 'لا يمكن حذف دور Super Admin الأساسي.',
            ], 403);
        }

        $role->delete();

        return response()->noContent();
    }

    private function ensureStaffRole(Role $role): void
    {
        abort_unless($role->guard_name === 'staff', 404);
    }
}
