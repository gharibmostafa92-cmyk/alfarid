<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\StaffRecipients;
use App\Services\Auth\UnifiedAuthService;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

class StaffAdministrationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;
    use StaffAdministrationRequests;

    public function test_system_identity_is_hidden_immutable_and_cannot_authenticate_even_if_activated(): void
    {
        $actor = $this->staff(['users.manage', 'inventory.all_branches']);
        $system = User::query()->where('is_system', true)->firstOrFail();
        $system->forceFill(['email' => 'changed-system@example.test', 'password' => 'System-password12', 'is_active' => true])->save();
        config()->set('orders.system_user_email', 'another@example.test');
        $this->api($actor, 'GET', '/users')->assertOk()->assertJsonMissing(['id' => $system->id]);
        $this->api($actor, 'GET', '/users/'.$system->id)->assertNotFound();
        $this->api($actor, 'PATCH', '/users/'.$system->id, ['password' => 'Replacement12', 'is_active' => true])->assertNotFound();
        $this->api($actor, 'DELETE', '/users/'.$system->id)->assertNotFound();
        $this->postJson('/api/v1/auth/login', ['login' => $system->email, 'password' => 'System-password12'])->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $system->email, 'password' => 'System-password12'])->assertUnauthorized();
        $this->api($system, 'GET', '/me')->assertForbidden();
    }

    public function test_branch_manager_can_only_read_and_modify_users_in_their_own_branch(): void
    {
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();
        $actor = $this->staff(['users.manage'], $a->id);
        $own = User::factory()->create(['branch_id' => $a->id]);
        $other = User::factory()->create(['branch_id' => $b->id]);
        $central = User::factory()->create(['branch_id' => null]);
        $this->api($actor, 'GET', '/users')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $other->id])->assertJsonMissing(['id' => $central->id]);
        $this->api($actor, 'GET', '/users?branch_id='.$b->id)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$other, $central] as $target) {
            $this->api($actor, 'GET', '/users/'.$target->id)->assertForbidden();
            $this->api($actor, 'PATCH', '/users/'.$target->id, ['password' => 'Replacement12'])->assertForbidden();
            $this->api($actor, 'DELETE', '/users/'.$target->id)->assertForbidden();
        }
        foreach ([null, $b->id] as $branch) {
            $this->api($actor, 'PATCH', '/users/'.$own->id, ['branch_id' => $branch])->assertForbidden();
            $this->api($actor, 'POST', '/users', ['name' => 'New', 'email' => 'new@example.test',
                'password' => 'Replacement12', 'branch_id' => $branch])->assertForbidden();
        }
        $this->api($actor, 'PATCH', '/users/'.$own->id, ['name' => 'Updated'])->assertOk();
        $this->api($actor, 'POST', '/users', ['name' => 'New', 'email' => 'new@example.test',
            'password' => 'Replacement12', 'branch_id' => $a->id])->assertCreated();
    }

    public function test_staff_without_branch_or_central_permission_fail_closed(): void
    {
        $actor = $this->staff(['users.manage']);
        $this->api($actor, 'GET', '/users')->assertForbidden();
        $target = User::factory()->create();
        $this->api($actor, 'PATCH', '/users/'.$target->id, ['name' => 'Denied'])->assertForbidden();
    }

    public function test_role_manager_cannot_clear_rename_or_delete_a_stronger_role(): void
    {
        $actor = $this->staff(['roles.manage']);
        $role = Role::create(['name' => 'Central finance', 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('settings.update', 'staff'));
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['permissions' => []])->assertForbidden();
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['name' => 'cashier'])->assertForbidden();
        $this->api($actor, 'DELETE', '/roles/'.$role->id)->assertForbidden();
        $this->assertSame('Central finance', $role->fresh()->name);
        $this->assertTrue($role->fresh()->hasPermissionTo('settings.update', 'staff'));
    }

    public function test_super_admin_can_retire_another_admin_but_cannot_retire_the_last_active_one(): void
    {
        $role = Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']);
        $a = User::factory()->create()->assignRole($role);
        $b = User::factory()->create()->assignRole($role);
        $this->api($a, 'DELETE', '/users/'.$b->id)->assertOk();
        $this->assertFalse($b->fresh()->is_active);
        // An inactive Super Admin must not count as a surviving administrator.
        $this->assertFalse(StaffAdministration::hasOtherActiveSuperAdmin($a));
        $this->api($a, 'PATCH', '/users/'.$a->id, ['is_active' => false])->assertForbidden();
        $this->api($a, 'PATCH', '/users/'.$b->id, ['roles' => []])->assertOk();
    }

    public function test_super_admin_gate_accepts_new_permissions_without_reseeding_the_role(): void
    {
        $role = Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']);
        $admin = User::factory()->create()->assignRole($role);
        Permission::findOrCreate('users.view', 'staff');
        $this->assertSame(0, $role->permissions()->count());
        $this->api($admin, 'GET', '/users')->assertOk();
    }

    public function test_notifications_resolve_role_permissions_and_respect_branch_activity_and_guard(): void
    {
        $branch = Branch::factory()->create();
        $role = Role::create(['name' => 'Inventory manager', 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('inventory.view', 'staff'));
        $manager = User::factory()->create(['branch_id' => $branch->id])->assignRole($role);
        $inactive = User::factory()->create(['branch_id' => $branch->id, 'is_active' => false])->assignRole($role);
        $other = User::factory()->create()->assignRole($role);
        $this->assertEqualsCanonicalizing([$manager->id, $other->id], StaffRecipients::withAnyPermission(['inventory.view'])->modelKeys());
        $this->assertSame([$manager->id], StaffRecipients::branchManagers($branch->id)->modelKeys());
        $this->assertFalse(StaffRecipients::withAnyPermission(['inventory.view'])->contains($inactive));
    }

    public function test_failed_attempts_from_one_ip_do_not_lock_out_correct_credentials_on_another(): void
    {
        config()->set('auth.unified_login_failures_hour', 2);
        config()->set('auth.unified_login_failures_day', 1000);
        $staff = User::factory()->create(['password' => 'Good-password12']);
        request()->server->set('REMOTE_ADDR', '192.0.2.1');
        $auth = app(UnifiedAuthService::class);
        for ($i = 0; $i < 2; $i++) {
            try {
                $auth->attempt($staff->email, 'wrong');
                $this->fail('Wrong password accepted');
            } catch (BusinessException $exception) {
                $this->assertSame(401, $exception->status);
            }
        }
        request()->server->set('REMOTE_ADDR', '192.0.2.2');
        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);
    }

    public function test_counters_reject_overflow_and_decrease_without_partial_settings_writes(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = $this->staff(['settings.manage']);
        foreach ([9999999999, PHP_INT_MAX] as $value) {
            $this->api($actor, 'PUT', '/settings', ['invoice_next_number' => $value])->assertUnprocessable();
        }
        $this->api($actor, 'PUT', '/settings', ['company_name' => 'Must roll back', 'invoice_next_number' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_next_number');
        $this->assertDatabaseMissing('settings', ['value' => 'Must roll back']);
    }

    public function test_constant_setting_reads_do_not_query_every_document_sequence(): void
    {
        $this->seed(SettingSeeder::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(14.0, app(SettingService::class)->get('default_tax_rate'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('number_seq', $queries[0]['query']);
    }

    public function test_all_mutable_counters_are_read_fresh_after_number_generation(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        foreach (['shift', 'expense', 'treasury_transfer', 'payment_notice'] as $type) {
            $key = $type.'_next_number';
            $before = $service->get($key);
            $service->generateNextNumber($type);
            $this->assertSame($before + 1, $service->get($key));
            $this->assertSame($before + 1, $service->getAllGrouped()['numbering'][$key]);
        }
    }

    public function test_sensitive_administration_is_audited_without_password_values(): void
    {
        $actor = $this->staff(['users.manage', 'inventory.all_branches']);
        $target = User::factory()->create();
        $this->api($actor, 'PATCH', '/users/'.$target->id, ['password' => 'Never-log-this12'])->assertOk();
        $log = DB::table('staff_audit_logs')->where('target_id', $target->id)->first();
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('user.updated', $log->action);
        $this->assertStringContainsString('password', $log->metadata);
        $this->assertStringNotContainsString('Never-log-this12', $log->metadata);
    }

    public function test_role_cache_is_invalidated_after_commit_even_if_repopulated_during_the_transaction(): void
    {
        $actor = $this->staff(['roles.manage']);
        $role = Role::create(['name' => 'Editable', 'guard_name' => 'staff']);
        $key = config('permission.cache.key');
        DB::listen(function ($query) use ($key): void {
            if (str_contains($query->sql, 'insert into "staff_audit_logs"')) {
                Cache::put($key, ['stale'], 3600);
            }
        });
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['permissions' => []])->assertOk();
        $this->assertNull(Cache::get($key));
    }

    public function test_invalid_route_ids_reserved_role_names_and_unsafe_logo_urls_are_rejected(): void
    {
        $actor = $this->staff(['roles.manage', 'users.manage', 'branches.manage', 'settings.manage']);
        foreach (['roles', 'branches', 'users'] as $resource) {
            $this->api($actor, 'GET', '/'.$resource.'/abc')->assertNotFound();
        }
        $this->api($actor, 'POST', '/roles', ['name' => 'super admin'])->assertForbidden();
        $role = Role::create(['name' => 'Rename target', 'guard_name' => 'staff']);
        $this->api($actor, 'PATCH', '/roles/'.$role->id, ['name' => 'super admin'])->assertForbidden();
        $this->api($actor, 'POST', '/roles', ['name' => 'Super Аdmin'])->assertUnprocessable();
        $this->api($actor, 'PUT', '/settings', ['company_logo' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->api($actor, 'POST', '/users', ['name' => 'Weak', 'email' => 'weak@example.test', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_legacy_staff_login_enforces_the_same_token_limit(): void
    {
        config()->set('auth.staff_max_tokens', 2);
        $actor = User::factory()->create(['password' => 'Good-password12']);
        $actor->assignRole(Role::findOrCreate('Operator', 'staff'));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            Auth::forgetGuards();
            $this->postJson('/api/v1/admin/auth/login', ['email' => $actor->email, 'password' => 'Good-password12'])->assertOk();
        }
        $this->assertSame(2, $actor->tokens()->count());
    }

    private function staff(array $permissions, ?int $branch = null): User
    {
        $staff = User::factory()->create(['branch_id' => $branch]);
        if (in_array('roles.manage', $permissions, true) || in_array('settings.manage', $permissions, true)) {
            $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        }
        foreach ($permissions as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $staff;
    }

    private function api(User $actor, string $method, string $path, array $body = [])
    {
        Auth::forgetGuards();

        return $this->withToken($actor->createToken('security-test')->plainTextToken)->json($method, '/api/v1/admin'.$path, $this->expectedStaffState($method, '/api/v1/admin'.$path, $body));
    }
}
