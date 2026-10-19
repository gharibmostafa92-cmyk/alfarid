<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FinancialPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        foreach (['purchases.approve', 'purchases.receive', 'purchase_requests.approve', 'supplier_payments.create', 'supplier_payments.update', 'supplier_payments.delete', 'supplier_invoices.post', 'supplier_invoices.cancel', 'returns.approve', 'returns.receive', 'returns.refund'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
