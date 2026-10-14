<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

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
            ->assertJsonPath('data.inventory.0.name', 'inventory.view')
            ->assertJsonPath('data.users.0.name', 'users.view')
            ->assertJsonMissing(['name' => 'web.only']);
    }

    public function test_role_crud_creates_updates_and_deletes_staff_roles(): void
    {
        Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
        Permission::create(['name' => 'users.create', 'guard_name' => 'staff']);
        $admin = $this->staffWithPermission('roles.manage');
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
        $permission = Permission::findOrCreate($permissionName, 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);

        return $staff;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
