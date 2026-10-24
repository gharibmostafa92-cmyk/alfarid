<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

class RolePermissionEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use StaffAdministrationRequests;

    public function test_role_resources_include_system_flag_and_user_count(): void
    {
        $role = Role::create(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $admin = $this->staffWithPermission('roles.view');
        $admin->assignRole($role);
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('data.0.is_system', true);
        $this->requestWithToken($token, 'GET', "/api/v1/admin/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('data.users_count', 1)
            ->assertJsonPath('data.is_system', true);
    }

    public function test_regular_role_resources_count_assigned_users(): void
    {
        $role = Role::create(['name' => 'Warehouse supervisor', 'guard_name' => 'staff']);
        User::factory()->count(2)->create()->each(fn (User $user) => $user->assignRole($role));
        $admin = $this->staffWithPermission('roles.view');
        $token = $admin->createToken('test')->plainTextToken;
        $this->requestWithToken($token, 'GET', "/api/v1/admin/roles/{$role->id}")
            ->assertOk()->assertJsonPath('data.users_count', 2)->assertJsonPath('data.is_system', false);
    }

    public function test_roles_index_forbids_staff_without_role_permissions(): void
    {
        $staff = User::factory()->create();
        $token = $staff->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/roles')->assertForbidden();
    }

    public function test_permissions_are_grouped_by_prefix_for_staff(): void
    {
        Permission::create(['name' => 'inventory.view', 'guard_name' => 'staff']);
        Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
        Permission::create(['name' => 'web.only', 'guard_name' => 'web']);
        $admin = $this->staffWithPermission('permissions.view');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonPath('data.inventory', fn ($permissions) => in_array('inventory.view', array_column($permissions, 'name'), true))
            ->assertJsonPath('data.users.0.name', 'users.view')
            ->assertJsonMissing(['name' => 'web.only']);
    }

    public function test_permission_list_uses_spatie_cache_for_repeated_reads(): void
    {
        Permission::create(['name' => 'permissions.view', 'guard_name' => 'staff']);
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        $permissionQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$permissionQueries): void {
            if (preg_match('/\\bfrom\\s+["`]?permissions["`]?\\b/i', $query->sql) === 1) {
                $permissionQueries[] = $query->sql;
            }
        });

        $controller = app(PermissionController::class);
        $controller->index($permissionRegistrar);
        $queriesAfterFirstRequest = count($permissionQueries);
        $this->assertGreaterThan(0, $queriesAfterFirstRequest);

        $controller->index($permissionRegistrar);

        $this->assertSame($queriesAfterFirstRequest, count($permissionQueries));
    }

    public function test_role_crud_creates_updates_and_deletes_staff_roles(): void
    {
        Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
        Permission::create(['name' => 'users.create', 'guard_name' => 'staff']);
        $admin = $this->staffWithPermissions(['roles.manage', 'users.view', 'users.create']);
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
            'name' => 'Operator',
            'permissions' => ['users.view'],
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.guard_name', 'staff')
            ->assertJsonPath('data.permissions.0', 'users.view');

        $roleId = $created->json('data.id');
        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/roles/{$roleId}", [
            'name' => 'Senior Operator',
            'permissions' => ['users.create'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Senior Operator')
            ->assertJsonPath('data.permissions.0', 'users.create');

        $this->requestWithToken($token, 'GET', "/api/v1/admin/roles/{$roleId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Senior Operator');

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/roles/{$roleId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_role_permissions_reject_permissions_from_another_guard(): void
    {
        Permission::create(['name' => 'web.only', 'guard_name' => 'web']);
        $admin = $this->staffWithPermission('roles.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
            'name' => 'Invalid Role',
            'permissions' => ['web.only'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_staff_cannot_grant_permissions_they_do_not_own_or_create_super_admin_roles(): void
    {
        Permission::findOrCreate('users.manage', 'staff');
        $admin = $this->staffWithPermissions(['roles.create', 'roles.update']);
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
            'name' => 'Overprivileged Role',
            'permissions' => ['users.manage'],
        ])->assertForbidden();

        $role = Role::create(['name' => 'Restricted Role', 'guard_name' => 'staff']);
        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/roles/{$role->id}", [
            'permissions' => ['users.manage'],
        ])->assertForbidden();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
            'name' => StaffRole::SuperAdmin->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'Overprivileged Role', 'guard_name' => 'staff']);
        $this->assertDatabaseMissing('roles', ['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
    }

    public function test_super_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->staffWithPermission('roles.manage');
        $superAdmin = Role::create([
            'name' => StaffRole::SuperAdmin->value,
            'guard_name' => 'staff',
        ]);
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/roles/{$superAdmin->id}")
            ->assertForbidden();

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/roles/{$superAdmin->id}", [
            'name' => 'Renamed Root',
        ])->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $superAdmin->id]);
    }

    public function test_role_assigned_to_staff_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'Assigned Role', 'guard_name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole($role);
        $admin = $this->staffWithPermission('roles.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/roles/{$role->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertTrue($staff->refresh()->hasRole($role, 'staff'));
    }

    public function test_seeder_creates_an_idempotent_super_admin_with_every_staff_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = Role::findByName(StaffRole::SuperAdmin->value, 'staff');
        $staffPermissionsCount = Permission::query()->where('guard_name', 'staff')->count();

        $this->assertSame($staffPermissionsCount, $superAdmin->permissions()->count());
        $this->assertGreaterThan(40, $staffPermissionsCount);
    }

    private function staffWithPermission(string $permissionName): User
    {
        return $this->staffWithPermissions([$permissionName]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function staffWithPermissions(array $permissionNames): User
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        foreach ($permissionNames as $permissionName) {
            $staff->givePermissionTo(Permission::findOrCreate($permissionName, 'staff'));
        }

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
