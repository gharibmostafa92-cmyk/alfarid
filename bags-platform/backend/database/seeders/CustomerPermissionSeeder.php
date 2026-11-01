<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CustomerPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'customers.approve',
            'customers.documents.view',
            'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
        ];

        $created = [];
        foreach ($permissions as $permission) {
            $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
        }

        Role::where('name', StaffRole::SuperAdmin->value)
            ->where('guard_name', 'staff')
            ->first()
            ?->givePermissionTo($created);
    }
}
