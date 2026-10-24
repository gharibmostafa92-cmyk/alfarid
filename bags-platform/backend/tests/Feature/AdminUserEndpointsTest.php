<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

class AdminUserEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use StaffAdministrationRequests;

    public function test_user_index_searches_name_email_and_phone(): void
    {
        $admin = $this->staffWithPermission('users.view');
        $token = $admin->createToken('test')->plainTextToken;
        $user = User::factory()->create(['name' => 'Unique Search Name', 'email' => 'unique-search@example.test', 'phone' => '01012345678']);
        foreach (['SEARCH NAME', 'UNIQUE-SEARCH@', '01012345'] as $term) {
            $this->requestWithToken($token, 'GET', '/api/v1/admin/users?search='.urlencode($term))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);
        }
    }

    public function test_user_index_filters_inactive_and_excludes_system_account(): void
    {
        $admin = $this->staffWithPermission('users.view');
        $token = $admin->createToken('test')->plainTextToken;
        $user = User::factory()->create(['is_active' => false]);
        User::query()->updateOrCreate(
            ['email' => config('orders.system_user_email', 'system@alfarid.local')],
            ['name' => 'System', 'password' => Hash::make('test-password'), 'is_active' => false],
        );
        $this->requestWithToken($token, 'GET', '/api/v1/admin/users?is_active=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);
    }

    public function test_empty_branch_filter_returns_only_users_without_branch(): void
    {
        $admin = $this->staffWithPermission('users.view');
        $admin->update(['branch_id' => null]);
        $token = $admin->createToken('test')->plainTextToken;
        $user = User::factory()->create(['branch_id' => null]);
        User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->requestWithToken($token, 'GET', '/api/v1/admin/users?branch_id=')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('data.1.id', $user->id);
    }

    public function test_user_index_forbids_an_authenticated_staff_member_without_permission(): void
    {
        $staff = User::factory()->create();
        $token = $staff->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/users')->assertForbidden();
    }

    public function test_user_crud_hashes_password_and_syncs_roles(): void
    {
        $branch = Branch::factory()->create();
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'staff']);
        $manager = $this->staffWithPermission('users.manage');
        $token = $manager->createToken('test')->plainTextToken;

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/users', [
            'name' => 'New Cashier',
            'email' => '  CASHIER@Example.Test  ',
            'phone' => '+201001234567',
            'password' => 'long-password12',
            'branch_id' => $branch->id,
            'roles' => ['Cashier'],
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.name', 'New Cashier')
            ->assertJsonPath('data.email', 'cashier@example.test')
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.roles.0.name', 'Cashier');

        $userId = $created->json('data.id');
        $user = User::findOrFail($userId);
        $this->assertTrue(Hash::check('long-password12', $user->password));
        $this->assertTrue($user->hasRole($cashierRole, 'staff'));

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/users/{$userId}", [
            'name' => 'Updated Cashier',
            'email' => ' Cashier@Example.Test ',
            'branch_id' => null,
            'roles' => [],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Cashier')
            ->assertJsonPath('data.branch', null)
            ->assertJsonPath('data.roles', []);

        $this->requestWithToken($token, 'GET', "/api/v1/admin/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'cashier@example.test');
    }

    public function test_user_index_filters_by_branch_and_staff_role_with_pagination(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'staff']);
        $admin = $this->staffWithPermission('users.view');
        $token = $admin->createToken('test')->plainTextToken;

        $matchingUser = User::factory()->create(['branch_id' => $branch->id]);
        $matchingUser->assignRole($cashierRole);
        User::factory()->create(['branch_id' => $branch->id]);
        User::factory()->create(['branch_id' => $otherBranch->id])->assignRole($cashierRole);

        $this->requestWithToken(
            $token,
            'GET',
            "/api/v1/admin/users?branch_id={$branch->id}&role=Cashier&per_page=1",
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingUser->id)
            ->assertJsonMissingPath('data.0.permissions')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_user_validation_rejects_duplicate_email_unknown_role_and_branch(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        $manager = $this->staffWithPermission('users.manage');
        $token = $manager->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Invalid User',
            'email' => ' EXISTING@Example.Test ',
            'password' => 'long-password12',
            'branch_id' => 999,
            'roles' => ['missing-role'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'branch_id', 'roles.0']);
    }

    public function test_destroy_deactivates_user_and_revokes_tokens(): void
    {
        $manager = $this->staffWithPermission('users.manage');
        $adminToken = $manager->createToken('test')->plainTextToken;
        $target = User::factory()->create();
        $targetToken = $target->createToken('other-device')->plainTextToken;

        $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->refresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);

        $this->requestWithToken($targetToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_destroy_forbids_self_and_super_admin_accounts(): void
    {
        $manager = $this->staffWithPermission('users.manage');
        $adminToken = $manager->createToken('test')->plainTextToken;

        $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$manager->id}")
            ->assertForbidden();

        $superAdminRole = Role::create([
            'name' => StaffRole::SuperAdmin->value,
            'guard_name' => 'staff',
        ]);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$superAdmin->id}")
            ->assertForbidden();

        $this->requestWithToken($adminToken, 'PATCH', "/api/v1/admin/users/{$superAdmin->id}", [
            'is_active' => false,
            'roles' => [],
        ])->assertForbidden();

        $this->requestWithToken($adminToken, 'PATCH', "/api/v1/admin/users/{$superAdmin->id}", [
            'email' => 'new-root@example.test',
            'password' => 'replacement-password12',
        ])->assertForbidden();

        $superAdminActor = User::factory()->create();
        $superAdminActor->assignRole($superAdminRole);
        $superAdminToken = $superAdminActor->createToken('super-admin')->plainTextToken;

        $this->requestWithToken($superAdminToken, 'PATCH', "/api/v1/admin/users/{$superAdmin->id}", [
            'is_active' => false,
        ])->assertOk();
        $this->requestWithToken($superAdminToken, 'PATCH', "/api/v1/admin/users/{$superAdmin->id}", [
            'roles' => [],
        ])->assertOk();

        $this->assertFalse($superAdmin->refresh()->is_active);
        $this->assertNotSame('new-root@example.test', $superAdmin->email);
        $this->assertTrue(Hash::check('password', $superAdmin->password));
    }

    public function test_only_super_admin_can_assign_super_admin_and_users_cannot_change_their_own_role_or_status(): void
    {
        Role::create(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $manager = $this->staffWithPermission('users.manage');
        $managerToken = $manager->createToken('manager')->plainTextToken;
        $target = User::factory()->create();

        $this->requestWithToken($managerToken, 'POST', '/api/v1/admin/users', [
            'name' => 'Unauthorized Root',
            'email' => 'unauthorized-root@example.test',
            'password' => 'long-password12',
            'roles' => [StaffRole::SuperAdmin->value],
        ])->assertForbidden();

        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$target->id}", [
            'roles' => [StaffRole::SuperAdmin->value],
        ])->assertForbidden();

        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$manager->id}", [
            'roles' => [],
        ])->assertForbidden();

        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$manager->id}", [
            'is_active' => false,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized-root@example.test']);
        $this->assertFalse($target->refresh()->hasRole(StaffRole::SuperAdmin->value, 'staff'));
        $this->assertTrue($manager->refresh()->is_active);
    }

    public function test_password_change_and_deactivation_revoke_all_target_tokens(): void
    {
        $manager = $this->staffWithPermission('users.manage');
        $managerToken = $manager->createToken('manager')->plainTextToken;

        $passwordTarget = User::factory()->create();
        $passwordToken = $passwordTarget->createToken('stolen-password-session')->plainTextToken;
        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$passwordTarget->id}", [
            'password' => 'new-password-for-user12',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $passwordTarget->id,
        ]);
        $this->requestWithToken($passwordToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();

        $inactiveTarget = User::factory()->create();
        $inactiveToken = $inactiveTarget->createToken('stolen-inactive-session')->plainTextToken;
        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$inactiveTarget->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse($inactiveTarget->refresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $inactiveTarget->id,
        ]);
        $this->requestWithToken($inactiveToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_email_change_revokes_all_target_sessions(): void
    {
        $manager = $this->staffWithPermission('users.manage');
        $managerToken = $manager->createToken('manager')->plainTextToken;
        $target = User::factory()->create();
        $targetToken = $target->createToken('old-email-session')->plainTextToken;
        $this->requestWithToken($managerToken, 'PATCH', "/api/v1/admin/users/{$target->id}", [
            'email' => 'updated-identity@example.test',
        ])->assertOk();
        $this->assertSame('updated-identity@example.test', $target->fresh()->email);
        $this->assertSame(0, $target->tokens()->count());
        $this->requestWithToken($targetToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_user_manager_cannot_assign_roles_with_permissions_they_do_not_own(): void
    {
        $role = Role::create(['name' => 'Finance Manager', 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('settings.manage', 'staff'));
        $manager = $this->staffWithPermission('users.manage');
        $token = $manager->createToken('manager')->plainTextToken;
        $target = User::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Unauthorized Finance Manager',
            'email' => 'unauthorized-finance@example.test',
            'password' => 'long-password12',
            'roles' => ['Finance Manager'],
        ])->assertForbidden();
        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/users/{$target->id}", [
            'roles' => ['Finance Manager'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized-finance@example.test']);
        $this->assertFalse($target->refresh()->hasRole($role, 'staff'));
    }

    public function test_user_manager_cannot_take_over_or_deactivate_a_more_privileged_account(): void
    {
        $manager = $this->staffWithPermission('users.manage');
        $token = $manager->createToken('manager')->plainTextToken;
        $target = $this->staffWithPermission('settings.manage');
        $targetToken = $target->createToken('target')->plainTextToken;
        $originalEmail = $target->email;

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/users/{$target->id}", [
            'email' => 'takeover@example.test',
            'password' => 'replacement-password12',
        ])->assertForbidden();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/users/{$target->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => $originalEmail, 'is_active' => true]);
        $this->assertTrue(Hash::check('password', $target->refresh()->password));
        $this->requestWithToken($targetToken, 'GET', '/api/v1/admin/me')->assertOk();
    }

    public function test_user_manager_can_assign_permissions_they_own_and_super_admin_can_assign_any_role(): void
    {
        $role = Role::create(['name' => 'Finance Manager', 'guard_name' => 'staff']);
        $permission = Permission::findOrCreate('settings.manage', 'staff');
        $role->givePermissionTo($permission);
        $manager = $this->staffWithPermission('users.manage');
        $manager->givePermissionTo($permission);
        $token = $manager->createToken('manager')->plainTextToken;
        $target = User::factory()->create();

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/users/{$target->id}", [
            'roles' => ['Finance Manager'],
        ])->assertOk();
        $this->assertTrue($target->refresh()->hasRole($role, 'staff'));

        $superRole = Role::create(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $superRole->givePermissionTo(Permission::findOrCreate('users.manage', 'staff'));
        $superAdmin = User::factory()->create()->assignRole($superRole);
        $superToken = $superAdmin->createToken('super-admin')->plainTextToken;

        $this->requestWithToken($superToken, 'POST', '/api/v1/admin/users', [
            'name' => 'Authorized Finance Manager',
            'email' => 'authorized-finance@example.test',
            'password' => 'long-password12',
            'roles' => ['Finance Manager'],
        ])->assertCreated();
        $this->assertTrue(User::where('email', 'authorized-finance@example.test')->firstOrFail()->hasRole($role, 'staff'));
    }

    private function staffWithPermission(string $permissionName): User
    {
        $permission = Permission::findOrCreate($permissionName, 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));

        return $staff;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $this->expectedStaffState($method, $uri, $payload));
    }
}
