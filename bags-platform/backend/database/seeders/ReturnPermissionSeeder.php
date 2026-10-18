<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** صلاحيات إدارة المرتجعات (المرحلة 9) — الإجراءات approve/receive/refund تحت returns.update. */
class ReturnPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'returns.view', 'returns.create', 'returns.update', 'returns.delete',
        ];

        $created = [];
        foreach ($permissions as $permission) {
            $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
        }

        $superAdmin = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $superAdmin->givePermissionTo($created);
    }
}
