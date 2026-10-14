<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const PERMISSIONS_BY_MODULE = [
        'branches' => ['view', 'create', 'update', 'delete', 'manage'],
        'users' => ['view', 'create', 'update', 'delete', 'manage'],
        'roles' => ['view', 'create', 'update', 'delete', 'manage'],
        'permissions' => ['view'],
        'customers' => ['view', 'create', 'update', 'delete', 'manage'],
        'suppliers' => ['view', 'create', 'update', 'delete', 'manage'],
        'products' => ['view', 'create', 'update', 'delete', 'manage'],
        'categories' => ['view', 'create', 'update', 'delete', 'manage'],
        'inventory' => ['view', 'create', 'update', 'delete', 'manage'],
        'warehouses' => ['view', 'create', 'update', 'delete', 'manage'],
        'orders' => ['view', 'create', 'update', 'delete', 'manage'],
        'sales' => ['view', 'create', 'update', 'delete', 'manage'],
        'purchases' => ['view', 'create', 'update', 'delete', 'manage'],
        'returns' => ['view', 'create', 'update', 'delete', 'manage'],
        'payments' => ['view', 'create', 'update', 'delete', 'manage'],
        'shipping' => ['view', 'create', 'update', 'delete', 'manage'],
        'transfers' => ['view', 'create', 'update', 'delete', 'manage'],
        'reports' => ['view', 'export'],
        'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
        'pricing' => ['view', 'create', 'update', 'delete', 'manage'],
        'settings' => ['manage'],
        'pos' => ['access', 'sales.view', 'sales.create', 'sales.refund'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS_BY_MODULE as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'staff');
            }
        }

        $superAdmin = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $superAdmin->syncPermissions(
            Permission::query()->where('guard_name', 'staff')->get(),
        );
    }
}
