<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuthEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_issues_staff_token_and_me_returns_branch_roles_and_permissions(): void
    {
        $branch = Branch::factory()->create();
        $permission = Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'staff']);
        $role->givePermissionTo($permission);
        $staff = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $staff->assignRole($role);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'admin@example.test')
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.roles.0.name', 'Manager')
            ->assertJsonPath('data.permissions.0', 'users.view')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'branch', 'roles', 'permissions'], 'token']);

        $token = $response->json('token');
        $this->getAsToken($token, '/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.branch.name', $branch->name);

        $this->assertTrue(Hash::check('secret-password', $staff->refresh()->password));
    }

    public function test_login_uses_only_staff_users_and_rejects_inactive_or_invalid_accounts(): void
    {
        Customer::create([
            'name' => 'Customer with shared credentials',
            'email' => 'shared@example.test',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'shared@example.test',
            'password' => 'secret-password',
        ])->assertUnauthorized();

        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_login_validates_required_email_and_password(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_returns_401_without_a_staff_token(): void
    {
        Auth::forgetGuards();

        $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    private function getAsToken(string $token, string $uri): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }
}
