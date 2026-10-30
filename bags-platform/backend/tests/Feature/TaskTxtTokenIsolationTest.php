<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * task.txt item 1 — token type isolation.
 *
 * Each account type token only opens its own routes. On other types'
 * routes it must return 403 with that type's middleware message, and
 * no database change may happen.
 */
class TaskTxtTokenIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_token_type_only_opens_its_own_routes(): void
    {
        $branch = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']));

        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole(Role::findOrCreate('cashier', 'staff'));
        $cashier->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));

        $retail = Customer::factory()->create();
        $wholesale = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $supplierUser = SupplierUser::factory()->create();

        $adminToken = $admin->createToken('admin')->plainTextToken;
        $posToken = $cashier->createToken('pos', ['pos'])->plainTextToken;
        $retailToken = $retail->createToken('store')->plainTextToken;
        $wholesaleToken = $wholesale->createToken('wholesale')->plainTextToken;
        $supplierToken = $supplierUser->createToken('supplier')->plainTextToken;

        $users = User::count();
        $customers = Customer::count();
        $suppliers = SupplierUser::count();
        $tokens = PersonalAccessToken::count();

        // Each token works on its own routes.
        $this->as($adminToken, 'get', '/api/v1/admin/me')->assertOk();
        $this->as($posToken, 'get', '/api/v1/pos/shifts/current')->assertOk();
        $this->as($retailToken, 'get', '/api/v1/store/account/orders')->assertOk();
        $this->as($wholesaleToken, 'get', '/api/v1/wholesale/dashboard')->assertOk();
        // Supplier portal is intentionally retired (ManufacturingBusiness
        // middleware): every token, including a valid supplier token,
        // gets 410 on /api/v1/supplier/*.
        $this->as($supplierToken, 'get', '/api/v1/supplier/dashboard')->assertGone();
        $this->as($adminToken, 'get', '/api/v1/supplier/dashboard')->assertGone();
        $this->as($retailToken, 'get', '/api/v1/supplier/dashboard')->assertGone();

        $staffMessage = 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.';
        $customerMessage = 'غير مصرح لك بالدخول كعميل.';
        $wholesaleMessage = 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.';

        // Retail customer token on other types' routes.
        $this->as($retailToken, 'get', '/api/v1/admin/me')->assertForbidden()->assertJsonPath('message', $staffMessage);
        $this->as($retailToken, 'get', '/api/v1/admin/users')->assertForbidden()->assertJsonPath('message', $staffMessage);
        $this->as($retailToken, 'post', '/api/v1/pos/shifts/open')->assertForbidden()->assertJsonPath('message', $staffMessage);
        $this->as($retailToken, 'get', '/api/v1/wholesale/dashboard')->assertForbidden()->assertJsonPath('message', $wholesaleMessage);

        // Staff token on customer / wholesale / supplier routes.
        $this->as($adminToken, 'get', '/api/v1/store/account/orders')->assertForbidden()->assertJsonPath('message', $customerMessage);
        $this->as($adminToken, 'get', '/api/v1/wholesale/dashboard')->assertForbidden()->assertJsonPath('message', $customerMessage);

        // Supplier token on admin / store / wholesale routes.
        $this->as($supplierToken, 'get', '/api/v1/admin/me')->assertForbidden()->assertJsonPath('message', $staffMessage);
        $this->as($supplierToken, 'get', '/api/v1/store/account/orders')->assertForbidden()->assertJsonPath('message', $customerMessage);
        $this->as($supplierToken, 'get', '/api/v1/wholesale/dashboard')->assertForbidden()->assertJsonPath('message', $customerMessage);

        // No database change happened.
        $this->assertSame($users, User::count());
        $this->assertSame($customers, Customer::count());
        $this->assertSame($suppliers, SupplierUser::count());
        $this->assertSame($tokens, PersonalAccessToken::count());
    }

    private function as(string $token, string $method, string $uri)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri);
    }
}
