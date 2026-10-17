<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OrderPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'orders.view', 'orders.update', 'orders.allocate',
        ];

        $created = [];
        foreach ($permissions as $permission) {
            $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
        }

        $superAdmin = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $superAdmin->givePermissionTo($created);
    }
}
