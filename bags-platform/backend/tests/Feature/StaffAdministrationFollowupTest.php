<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Auth\UnifiedAuthService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

class StaffAdministrationFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    use StaffAdministrationRequests;

    public function test_branch_manager_cannot_mutate_a_global_role_even_when_they_own_every_permission(): void
    {
        $branch = Branch::factory()->create();
        $actor = $this->staff(['roles.manage', 'pos.sales.create'], $branch->id);
        $role = Role::create(['name' => 'Shared operator', 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('pos.sales.create', 'staff'));
        User::factory()->create(['branch_id' => Branch::factory()->create()->id])->assignRole($role);
        $this->api($actor, 'POST', '/roles', ['name' => 'Branch role', 'permissions' => []])->assertForbidden();
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['permissions' => []])->assertForbidden();
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['name' => 'Changed'])->assertForbidden();
        $this->api($actor, 'DELETE', '/roles/'.$role->id)->assertForbidden();
        $this->assertSame('Shared operator', $role->fresh()->name);
        $this->assertSame(['pos.sales.create'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertSame(4, DB::table('staff_audit_logs')->where('actor_id', $actor->id)->where('action', 'administration.denied')->count());
        $this->assertDatabaseMissing('roles', ['name' => 'Branch role']);
    }

    public function test_global_settings_mutation_requires_central_scope(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = $this->staff(['settings.manage'], Branch::factory()->create()->id);
        $this->api($actor, 'PUT', '/settings', ['default_tax_rate' => 20])->assertForbidden();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $this->api($actor, 'PUT', '/settings', ['default_tax_rate' => 20])->assertOk()->assertJsonPath('data.tax.default_tax_rate', 20);
    }

    public function test_role_version_rejects_a_stale_permission_list_even_without_a_name_change(): void
    {
        $actor = $this->staff(['roles.manage', 'users.view', 'users.update', 'inventory.all_branches']);
        $role = Role::create(['name' => 'Concurrent role', 'guard_name' => 'staff']);
        $version = $this->api($actor, 'GET', '/roles/'.$role->id)->assertOk()->json('data.version');
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['expected_version' => $version, 'permissions' => ['users.view']])
            ->assertOk()->assertJsonPath('data.version', $version + 1);
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['expected_version' => $version, 'permissions' => ['users.update']])->assertConflict();
        $this->assertSame(['users.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_user_version_rejects_stale_roles_and_preserves_the_first_update(): void
    {
        $actor = $this->staff(['users.manage', 'inventory.all_branches']);
        $target = User::factory()->create();
        $role = Role::create(['name' => 'Operator', 'guard_name' => 'staff']);
        $version = $this->api($actor, 'GET', '/users/'.$target->id)->json('data.version');
        $this->api($actor, 'PATCH', '/users/'.$target->id, ['roles' => [$role->name], 'expected_version' => $version])
            ->assertOk()->assertJsonPath('data.version', $version + 1);
        $this->api($actor, 'PATCH', '/users/'.$target->id, ['roles' => [], 'expected_version' => $version])->assertConflict();
        $this->assertTrue($target->fresh()->hasRole($role));
    }

    public function test_self_credentials_require_current_password_and_do_not_audit_its_value(): void
    {
        $actor = $this->staff(['users.manage', 'inventory.all_branches']);
        $actor->update(['password' => 'Original-password12']);
        $this->api($actor, 'PATCH', '/users/'.$actor->id, ['email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->api($actor, 'PATCH', '/users/'.$actor->id, ['password' => 'Replacement-password12', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('Original-password12', $actor->fresh()->password));
        $this->api($actor, 'PATCH', '/users/'.$actor->id, ['email' => 'changed@example.test',
            'password' => 'Replacement-password12', 'current_password' => 'Original-password12'])->assertOk();
        $this->assertTrue(Hash::check('Replacement-password12', $actor->fresh()->password));
        $this->assertSame(0, $actor->tokens()->count());
        $metadata = DB::table('staff_audit_logs')->where('actor_id', $actor->id)->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('Original-password12', $metadata);
        $this->assertStringNotContainsString('Replacement-password12', $metadata);
        $this->assertStringNotContainsString('current_password', $metadata);
    }

    public function test_builtin_role_identity_cannot_be_created_renamed_or_deleted_but_cashier_permissions_can_change(): void
    {
        $actor = $this->staff(['roles.manage', 'inventory.all_branches', 'pos.sales.create']);
        $cashier = Role::create(['name' => 'cashier', 'guard_name' => 'staff']);
        $other = Role::create(['name' => 'Other', 'guard_name' => 'staff']);
        foreach (['ＣＡＳＨＩＥＲ', 'Ｓuper Admin', 'CASHIER'] as $name) {
            $this->api($actor, 'POST', '/roles', ['name' => $name])->assertForbidden();
            $this->api($actor, 'PATCH', '/roles/'.$other->id, ['name' => $name])->assertForbidden();
        }
        $this->api($actor, 'PATCH', '/roles/'.$cashier->id, ['name' => 'Operator'])->assertForbidden();
        $this->api($actor, 'DELETE', '/roles/'.$cashier->id)->assertForbidden();
        $this->api($actor, 'PATCH', '/roles/'.$cashier->id, ['permissions' => ['pos.sales.create']])->assertOk()
            ->assertJsonPath('data.is_builtin', true)->assertJsonPath('data.name', 'cashier');
        $this->api($actor, 'POST', '/roles', ['name' => 'Ｏperator'])->assertCreated()->assertJsonPath('data.name', 'Operator');
    }

    public function test_legacy_login_rejects_roleless_staff_and_cashiers_of_inactive_branches(): void
    {
        $staff = User::factory()->create(['password' => 'Good-password12']);
        $body = ['email' => $staff->email, 'password' => 'Good-password12'];
        $this->postJson('/api/v1/admin/auth/login', $body)->assertForbidden();
        $staff->assignRole(Role::create(['name' => 'cashier', 'guard_name' => 'staff']));
        $staff->update(['branch_id' => Branch::factory()->create(['is_active' => false])->id]);
        $this->postJson('/api/v1/admin/auth/login', $body)->assertForbidden();
        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_distributed_failures_cannot_globally_lock_a_staff_account_and_success_resets_its_pair_budget(): void
    {
        config(['auth.unified_login_failures_hour' => 3, 'auth.unified_login_failures_day' => 2]);
        $staff = User::factory()->create(['password' => 'Good-password12']);
        $auth = app(UnifiedAuthService::class);
        request()->server->set('REMOTE_ADDR', '192.0.2.1');
        $this->failLogin($auth, $staff->email);
        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);
        $this->failLogin($auth, $staff->email);
        $this->failLogin($auth, $staff->email);
        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);
        request()->server->set('REMOTE_ADDR', '192.0.2.2');
        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);
    }

    public function test_ip_failure_budget_is_shared_across_identifiers_and_is_not_cleared_by_a_success(): void
    {
        config(['auth.unified_login_ip_failures_hour' => 2]);
        $auth = app(UnifiedAuthService::class);
        $staff = User::factory()->create(['password' => 'Good-password12']);
        request()->server->set('REMOTE_ADDR', '192.0.2.10');
        $this->failLogin($auth, 'unknown-one@example.test');
        $auth->attempt($staff->email, 'Good-password12');
        $this->failLogin($auth, 'unknown-two@example.test');
        $this->expectException(TooManyRequestsHttpException::class);
        $auth->attempt('unknown-three@example.test', 'wrong');
    }

    public function test_audit_keeps_role_permission_branch_and_activation_before_and_after(): void
    {
        $actor = $this->staff(['users.manage', 'roles.manage', 'inventory.all_branches', 'pos.sales.create']);
        $branch = Branch::factory()->create();
        $target = User::factory()->create(['branch_id' => null]);
        $role = Role::create(['name' => 'Audit role', 'guard_name' => 'staff']);
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['permissions' => ['pos.sales.create']])->assertOk();
        $this->api($actor, 'PATCH', '/users/'.$target->id, ['roles' => [$role->name], 'branch_id' => $branch->id, 'is_active' => false])->assertOk();
        $log = DB::table('staff_audit_logs')->where('action', 'user.updated')->where('target_id', $target->id)->first();
        $data = json_decode($log->metadata, true);
        $this->assertSame([], $data['before']['roles']);
        $this->assertSame(['Audit role'], $data['after']['roles']);
        $this->assertNull($data['before']['branch_id']);
        $this->assertSame($branch->id, $data['after']['branch_id']);
        $this->assertTrue($data['before']['is_active']);
        $this->assertFalse($data['after']['is_active']);
        $log = DB::table('staff_audit_logs')->where('action', 'role.updated')->first();
        $this->assertSame(['pos.sales.create'], json_decode($log->metadata, true)['after']['permissions']);
    }

    public function test_tax_null_and_arrays_for_text_settings_are_rejected_before_storage(): void
    {
        $actor = $this->staff(['settings.manage', 'inventory.all_branches']);
        foreach ([null, '', ['invalid']] as $value) {
            $this->api($actor, 'PUT', '/settings', ['default_tax_rate' => $value])->assertUnprocessable()->assertJsonValidationErrors('default_tax_rate');
        }
        $this->api($actor, 'PUT', '/settings', ['company_name' => [], 'company_email' => [], 'company_logo' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['company_name', 'company_email', 'company_logo']);
    }

    public function test_percent_and_underscore_in_user_search_are_literals(): void
    {
        $actor = $this->staff(['users.view', 'inventory.all_branches']);
        $target = User::factory()->create(['name' => 'Literal %_ Staff']);
        User::factory()->create(['name' => 'Unrelated Staff']);
        $this->api($actor, 'GET', '/users?search='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_only_configured_proxy_can_supply_client_ip(): void
    {
        config(['app.trusted_proxies' => '192.0.2.10']);
        (new AppServiceProvider($this->app))->boot();
        Route::get('/followup-client-ip', fn (Request $request) => ['ip' => $request->ip()]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeader('X-Forwarded-For', '198.51.100.20')
            ->getJson('/followup-client-ip')->assertOk()->assertJsonPath('ip', '198.51.100.20');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->withHeader('X-Forwarded-For', '198.51.100.20')
            ->getJson('/followup-client-ip')->assertOk()->assertJsonPath('ip', '192.0.2.11');
    }

    public function test_routes_with_controller_or_request_authorization_reject_staff_without_permissions(): void
    {
        $actor = User::factory()->create();
        foreach (['/inventory', '/transfers', '/stocktakes', '/products', '/categories', '/production-receipts', '/price-lists'] as $path) {
            $this->api($actor, 'GET', $path)->assertForbidden();
            $this->api($actor, 'POST', $path === '/inventory' ? '/inventory/adjustments' : $path, [])->assertForbidden();
        }
    }

    public function test_empty_permission_groups_are_an_object(): void
    {
        Permission::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = User::factory()->create()->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']));
        $response = $this->api($actor, 'GET', '/permissions')->assertOk();
        $this->assertStringContainsString('"data":{}', $response->getContent());
    }

    private function failLogin(UnifiedAuthService $auth, string $identifier): void
    {
        try {
            $auth->attempt($identifier, 'wrong');
            $this->fail('Wrong password was accepted');
        } catch (BusinessException $exception) {
            $this->assertSame(401, $exception->status);
        } catch (TooManyRequestsHttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }
    }

    private function staff(array $permissions, ?int $branch = null): User
    {
        $actor = User::factory()->create(['branch_id' => $branch]);
        foreach ($permissions as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }

        return $actor;
    }

    private function api(User $actor, string $method, string $path, array $body = [])
    {
        Auth::forgetGuards();

        return $this->withToken($actor->createToken('followup-test')->plainTextToken)->json($method, '/api/v1/admin'.$path, $this->expectedStaffState($method, '/api/v1/admin'.$path, $body));
    }
}
