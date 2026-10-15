<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class InventoryPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'inventory.view',         // عرض الأرصدة وحركات المخزون
            'inventory.adjust',       // عمل تسويات يدوية
            'inventory.all_branches', // الوصول لكل الفروع (امتياز صريح — بدونه الموظف بلا فرع مرفوض)
            'inventory.view_cost',    // رؤية تكلفة الشراء في حركات الدفتر
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
            'transfers.approve', 'transfers.ship', 'transfers.receive',
            'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete',
            'stocktakes.approve',
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
