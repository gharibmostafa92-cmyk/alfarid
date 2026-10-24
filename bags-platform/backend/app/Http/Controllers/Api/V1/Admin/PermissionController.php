<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    public function index(PermissionRegistrar $permissionRegistrar): JsonResponse
    {
        $permissions = $permissionRegistrar
            ->getPermissions(['guard_name' => 'staff'])
            ->sortBy('name');

        $groups = $permissions
            ->groupBy(fn (Permission $permission): string => Str::before($permission->name, '.'))
            ->map(fn ($group) => $group->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
            ])->values());

        return response()->json(['data' => (object) $groups->all()]);
    }
}
