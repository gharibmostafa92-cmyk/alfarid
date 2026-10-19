<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** صلاحيات إدارة المرتجعات (المرحلة 9) — الاعتماد والاستلام والاسترداد بصلاحيات مستقلة. */
class ReturnPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'returns.view', 'returns.create', 'returns.update', 'returns.delete', 'returns.approve', 'returns.receive', 'returns.refund',
        ];

        $created = [];
        foreach ($permissions as $permission) {
            $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
        }

        $superAdmin = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $superAdmin->givePermissionTo($created);
    }
}
