<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UnifiedLoginDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Unified login demo accounts are only available locally.');
        }
        $password = env('UNIFIED_DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \LogicException('Set UNIFIED_DEMO_PASSWORD to at least 12 characters.');
        }

        $branch = Branch::firstOrCreate(['code' => 'UNIFIED-QA'], ['name' => 'فرع اختبار الدخول', 'type' => 'branch', 'is_active' => true]);
        $adminRole = Role::findOrCreate('unified-qa-admin', 'staff');
        foreach (['users.view', 'products.view', 'products.view_cost', 'roles.manage', 'pos.access'] as $permission) {
            $adminRole->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $cashierRole = Role::findOrCreate('cashier', 'staff');
        $cashierRole->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));

        foreach (['admin' => $adminRole, 'cashier' => $cashierRole] as $kind => $role) {
            $user = User::firstOrCreate(['email' => "unified-{$kind}@example.test"], [
                'name' => $kind === 'admin' ? 'مدير اختبار الدخول' : 'كاشير اختبار الدخول',
                'password' => $password, 'is_active' => true, 'branch_id' => $branch->id,
            ]);
            $user->assignRole($role);
        }

        $supplier = Supplier::firstOrCreate(['code' => 'UNIFIED-QA'], ['name' => 'مورد اختبار الدخول', 'is_active' => true]);
        SupplierUser::firstOrCreate(['email' => 'unified-supplier@example.test'], [
            'name' => 'مستخدم مورد الاختبار', 'supplier_id' => $supplier->id, 'password' => $password, 'is_active' => true,
        ]);

        foreach ([
            ['retail', CustomerType::Retail, CustomerStatus::Approved, '01070000001'],
            ['approved', CustomerType::Wholesale, CustomerStatus::Approved, '01070000002'],
            ['pending', CustomerType::Wholesale, CustomerStatus::Pending, '01070000003'],
            ['rejected', CustomerType::Distributor, CustomerStatus::Rejected, '01070000004'],
            ['suspended', CustomerType::Retail, CustomerStatus::Suspended, '01070000005'],
        ] as [$kind, $type, $status, $phone]) {
            Customer::firstOrCreate(['email' => "unified-{$kind}@example.test"], [
                'name' => "عميل اختبار {$kind}", 'phone' => $phone, 'password' => $password,
                'type' => $type, 'status' => $status, 'email_verified_at' => now(),
                'company_name' => $type === CustomerType::Retail ? null : 'شركة اختبار الدخول',
                'tax_number' => $type === CustomerType::Retail ? null : '987654321',
                'commercial_register' => $type === CustomerType::Retail ? null : '987654',
                'rejection_reason' => $status === CustomerStatus::Rejected ? 'مستندات غير مكتملة' : null,
                'suspension_reason' => $status === CustomerStatus::Suspended ? 'حساب اختبار موقوف' : null,
            ]);
        }
    }
}
