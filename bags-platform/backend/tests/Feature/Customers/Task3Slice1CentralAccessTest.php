<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CustomerPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Task3Slice1CentralAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function centralToken(array $permissions): string
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user = User::factory()->create(['branch_id' => null]);
        $user->givePermissionTo(array_merge($permissions, ['inventory.all_branches']));

        return $user->createToken('staff-token', ['*'])->plainTextToken;
    }

    private function branchToken(array $permissions): string
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo($permissions);

        return $user->createToken('staff-token', ['*'])->plainTextToken;
    }

    private function pendingWholesaleCustomer(): Customer
    {
        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'company_name' => 'Test Central Company',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
        ]);
        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        return $customer;
    }

    public function test_branch_staff_cannot_approve_wholesale_customer(): void
    {
        $token = $this->branchToken(['customers.view', 'customers.approve']);
        $customer = $this->pendingWholesaleCustomer();

        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/approve')
            ->assertForbidden();

        $this->assertSame(CustomerStatus::Pending, $customer->fresh()->status);
    }

    public function test_branch_staff_cannot_reject_suspend_or_reactivate(): void
    {
        $token = $this->branchToken(['customers.view', 'customers.approve']);
        $customer = $this->pendingWholesaleCustomer();

        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/reject', ['reason' => 'x'])
            ->assertForbidden();
        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/suspend', ['reason' => 'x'])
            ->assertForbidden();
        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/reactivate')
            ->assertForbidden();

        $this->assertSame(CustomerStatus::Pending, $customer->fresh()->status);
    }

    public function test_branch_staff_cannot_review_or_store_documents(): void
    {
        $token = $this->branchToken(['customers.view', 'customers.approve', 'customers.documents.view', 'customers.update']);
        $customer = $this->pendingWholesaleCustomer();
        $document = $customer->documents()->firstOrFail();

        $this->apiAs($token, 'PATCH', '/api/v1/admin/customers/'.$customer->id.'/documents/'.$document->id, [
            'status' => CustomerDocumentStatus::Rejected->value,
            'note' => 'invalid document',
        ])->assertForbidden();
        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/documents')
            ->assertForbidden();

        $this->assertSame(
            CustomerDocumentStatus::Verified->value,
            $document->fresh()->status->value
        );
    }

    public function test_central_staff_can_still_approve(): void
    {
        $token = $this->centralToken(['customers.view', 'customers.approve']);
        $customer = $this->pendingWholesaleCustomer();

        $this->apiAs($token, 'POST', '/api/v1/admin/customers/'.$customer->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_branch_user_index_stays_scoped_to_own_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        Permission::findOrCreate('users.view', 'staff');
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo(['users.view']);
        $token = $user->createToken('staff-token', ['*'])->plainTextToken;
        $other = Branch::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['branch_id' => $other->id]);

        // Mismatched or empty filters stay fail-closed: 200 with no rows
        // from other branches (pinned existing contract), never leaking.
        $this->apiAs($token, 'GET', '/api/v1/admin/users?branch_id='.$branch->id)->assertOk();
        $this->apiAs($token, 'GET', '/api/v1/admin/users?branch_id='.$other->id)
            ->assertOk()
            ->assertJsonMissing(['id' => $outsider->id]);
    }

    public function test_staff_role_change_takes_effect_for_fresh_permission_state(): void
    {
        Permission::findOrCreate('customers.view', 'staff');
        $roleA = Role::create(['name' => 'task3-role-a', 'guard_name' => 'staff']);
        $roleA->givePermissionTo('customers.view');
        Role::create(['name' => 'task3-role-b', 'guard_name' => 'staff']);

        $target = User::factory()->create(['branch_id' => null]);
        $target->givePermissionTo(['inventory.all_branches']);
        $target->assignRole($roleA);
        $this->assertTrue($target->can('customers.view'));

        $admin = User::factory()->create(['branch_id' => null]);
        $admin->givePermissionTo(['users.view', 'users.update', 'users.manage', 'inventory.all_branches']);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;

        $this->apiAs($adminToken, 'PUT', '/api/v1/admin/users/'.$target->id, [
            'expected_version' => $target->fresh()->lock_version,
            'roles' => ['task3-role-b'],
            'current_password' => 'password',
        ])->assertOk();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $fresh = User::findOrFail($target->id);
        $fresh->unsetRelation('roles');
        $fresh->unsetRelation('permissions');
        $this->assertFalse($fresh->can('customers.view'));
    }

    public function test_role_permission_addition_revokes_existing_staff_tokens(): void
    {
        $permission = Permission::findOrCreate('finance.reports.view', 'staff');
        $role = Role::query()->create(['name' => 'task3-report-viewer', 'guard_name' => 'staff']);
        $role->refresh();
        $staff = User::factory()->create();
        $staff->assignRole($role);
        $staff->createToken('before-permission-grant', ['*']);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;

        $this->withToken($adminToken)->putJson('/api/v1/admin/roles/'.$role->id, [
            'expected_version' => $role->lock_version,
            'permissions' => [$permission->name],
            'current_password' => 'password',
        ])->assertOk();

        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_role_grant_with_wrong_password_verifies_outside_transaction(): void
    {
        Permission::findOrCreate('users.manage', 'staff');
        $role = Role::create(['name' => 'task3-grant-target', 'guard_name' => 'staff']);
        $role->givePermissionTo('users.manage');

        $admin = User::factory()->create(['password' => 'CorrectPass123']);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;
        $target = User::factory()->create();

        // Level 1 is the test's own transaction; the action opens level 2.
        // Bcrypt must never run while the action transaction (and its global
        // advisory lock) is open.
        $checkLevels = [];
        Hash::shouldReceive('check')->andReturnUsing(function ($value, $hashed) use (&$checkLevels) {
            $checkLevels[] = DB::transactionLevel();

            return password_verify($value, $hashed);
        });
        $this->apiAs($adminToken, 'PUT', '/api/v1/admin/users/'.$target->id, [
            'expected_version' => $target->fresh()->lock_version,
            'roles' => ['task3-grant-target'],
            'current_password' => 'WrongPass123',
        ])->assertStatus(422);

        $this->assertNotEmpty($checkLevels);
        $this->assertLessThanOrEqual(1, max($checkLevels));
        $this->assertSame([], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_staff_creation_with_wrong_password_verifies_outside_transaction(): void
    {
        Permission::findOrCreate('users.create', 'staff');
        Permission::findOrCreate('users.manage', 'staff');
        Role::create(['name' => 'task3-create-target', 'guard_name' => 'staff']);

        $admin = User::factory()->create(['password' => 'CorrectPass123']);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;

        $before = User::count();
        $checkLevels = [];
        Hash::shouldReceive('check')->andReturnUsing(function ($value, $hashed) use (&$checkLevels) {
            $checkLevels[] = DB::transactionLevel();

            return password_verify($value, $hashed);
        });
        Hash::shouldReceive('make')->andReturnUsing(static fn ($value) => password_hash($value, PASSWORD_BCRYPT));
        $this->apiAs($adminToken, 'POST', '/api/v1/admin/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@example.com',
            'password' => 'NewStaffPass123',
            'roles' => ['task3-create-target'],
            'current_password' => 'WrongPass123',
        ])->assertStatus(422);

        $this->assertNotEmpty($checkLevels);
        $this->assertLessThanOrEqual(1, max($checkLevels));
        $this->assertSame($before, User::count());
    }

    public function test_role_update_with_wrong_password_verifies_outside_transaction(): void
    {
        Permission::findOrCreate('finance.reports.view', 'staff');
        $role = Role::query()->create(['name' => 'task3-role-lock', 'guard_name' => 'staff']);
        $role->refresh();

        $admin = User::factory()->create(['password' => 'CorrectPass123']);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;

        $checkLevels = [];
        Hash::shouldReceive('check')->andReturnUsing(function ($value, $hashed) use (&$checkLevels) {
            $checkLevels[] = DB::transactionLevel();

            return password_verify($value, $hashed);
        });
        $this->withToken($adminToken)->putJson('/api/v1/admin/roles/'.$role->id, [
            'expected_version' => $role->lock_version,
            'permissions' => ['finance.reports.view'],
            'current_password' => 'WrongPass123',
        ])->assertStatus(422);

        $this->assertNotEmpty($checkLevels);
        $this->assertLessThanOrEqual(1, max($checkLevels));
    }
}
