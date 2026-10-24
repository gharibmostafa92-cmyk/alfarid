<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\StaffTokenService;
use App\Services\Auth\UnifiedAuthService;
use App\Services\OrderService;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

class StaffFinalReviewTest extends TestCase
{
    use LazilyRefreshDatabase, StaffAdministrationRequests;

    public function test_password_and_email_changes_invalidate_previously_issued_handoffs(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['password' => 'Original-password12']);
        $target->assignRole(Role::findOrCreate('Operator', 'staff'));
        foreach ([['password' => 'Replacement-password12'], ['email' => 'updated-login@example.test']] as $change) {
            $code = app(StaffHandoffService::class)->issue($target->fresh());
            $this->api($admin, 'PATCH', '/users/'.$target->id, $change)->assertOk();
            Auth::forgetGuards();
            $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertUnauthorized();
            $this->assertSame(0, $target->tokens()->count());
        }
        $code = app(StaffHandoffService::class)->issue($target->fresh());
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertOk();
        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_token_issuance_rechecks_the_handoff_fingerprint_after_locking_the_user(): void
    {
        $user = User::factory()->create();
        $fingerprint = StaffHandoffService::credentialFingerprint($user);
        $user->update(['password' => 'Changed-password12']);
        try {
            app(StaffTokenService::class)->issue($user, $fingerprint);
            $this->fail('Old credentials minted a token');
        } catch (BusinessException $exception) {
            $this->assertSame(401, $exception->status);
        }
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_old_format_handoff_payloads_are_not_accepted(): void
    {
        $user = User::factory()->create();
        $code = str_repeat('a', 64);
        Cache::put('auth:handoff:'.hash('sha256', $code), ['user_id' => $user->id], 60);
        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertUnauthorized();
    }

    public function test_an_in_flight_password_rehash_cannot_undo_a_concurrent_administrative_reset(): void
    {
        $snapshot = User::factory()->create(['password' => 'Original-password12']);
        $original = $snapshot->password;
        $replacement = Hash::make('Admin-reset-password12');
        DB::table('users')->where('id', $snapshot->id)->update(['password' => $replacement]);
        $manager = Hash::getFacadeRoot();
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('needsRehash')->with($original)->andReturn(true);
        Hash::swap($proxy);
        try {
            $method = new \ReflectionMethod(UnifiedAuthService::class, 'rehashIfNeeded');
            try {
                $method->invoke(app(UnifiedAuthService::class), $snapshot, 'Original-password12');
                $this->fail('Rehash overwrote the new password');
            } catch (BusinessException $exception) {
                $this->assertSame(401, $exception->status);
            }
        } finally {
            Hash::swap($manager);
        }
        $this->assertTrue(Hash::check('Admin-reset-password12', $snapshot->fresh()->password));
    }

    public function test_staff_tokens_expire_after_two_hours(): void
    {
        config(['auth.staff_token_minutes' => 120, 'sanctum.expiration' => 1440]);
        $user = User::factory()->create();
        $token = app(StaffTokenService::class)->issue($user);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($user->tokens()->latest('id')->first()->expires_at), 0.1);
        $this->travel(121)->minutes();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_valid_staff_and_customer_credentials_survive_aggregate_ip_and_daily_failure_budgets(): void
    {
        config(['auth.unified_login_ip_failures_hour' => 2, 'auth.unified_login_failures_day' => 2]);
        $user = User::factory()->create(['password' => 'Good-password12']);
        $customer = Customer::factory()->create(['password' => 'Customer-password12']);
        $auth = app(UnifiedAuthService::class);
        $this->wrong($auth, $user->email);
        $this->wrong($auth, $user->email);
        $this->assertSame($user->id, $auth->attempt($user->email, 'Good-password12')->id);
        $this->wrong($auth, $customer->phone);
        $this->wrong($auth, $customer->phone);
        $this->assertSame($customer->id, $auth->attempt($customer->phone, 'Customer-password12')->id);
        request()->server->set('REMOTE_ADDR', '192.0.2.44');
        $this->assertSame($customer->id, $auth->attempt($customer->phone, 'Customer-password12')->id);
    }

    public function test_pair_budget_remains_strict(): void
    {
        config(['auth.unified_login_failures_hour' => 2]);
        $user = User::factory()->create(['password' => 'Good-password12']);
        $auth = app(UnifiedAuthService::class);
        $this->wrong($auth, $user->email);
        $this->wrong($auth, $user->email);
        $this->expectException(TooManyRequestsHttpException::class);
        $auth->attempt($user->email, 'Good-password12');
    }

    public function test_failure_threshold_creates_one_real_notification_for_central_administrators(): void
    {
        config(['auth.unified_login_failures_day' => 1]);
        $admin = $this->admin();
        $user = User::factory()->create();
        $auth = app(UnifiedAuthService::class);
        $this->wrong($auth, $user->email);
        $this->wrong($auth, $user->email);
        $notification = $admin->notifications()->firstOrFail();
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('authentication_security_alert', $notification->data['kind']);
        $this->assertSame($user->id, $notification->data['staff_id']);
    }

    public function test_transactional_static_setting_reads_only_load_one_snapshot_even_across_savepoints(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->get('tax_enabled');
        DB::transaction(fn () => $service->get('default_tax_rate'));
        $service->get('prices_include_tax');
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "settings"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
    }

    public function test_uncommitted_settings_are_visible_locally_and_disappear_after_rollback(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $this->assertSame('Alfarid', $service->get('company_name'));
        DB::beginTransaction();
        try {
            $service->updateBatch(['company_name' => 'Uncommitted company']);
            $this->assertSame('Uncommitted company', $service->get('company_name'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame('Alfarid', $service->get('company_name'));
    }

    public function test_stale_settings_are_rejected_without_overwriting_the_other_admin_or_partially_saving(): void
    {
        $this->seed(SettingSeeder::class);
        $admin = $this->admin();
        $this->api($admin, 'PUT', '/settings', ['default_tax_rate' => 20, 'expected' => ['default_tax_rate' => 14]])->assertOk();
        $this->api($admin, 'PUT', '/settings', ['default_tax_rate' => 25, 'company_name' => 'Must not save',
            'expected' => ['default_tax_rate' => 14, 'company_name' => 'Alfarid']])->assertConflict();
        $this->assertDatabaseHas('settings', ['key' => 'default_tax_rate', 'value' => '20']);
        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Alfarid']);
    }

    public function test_settings_audit_contains_financial_before_and_after_values(): void
    {
        $this->seed(SettingSeeder::class);
        $this->api($this->admin(), 'PUT', '/settings', ['default_tax_rate' => 21])->assertOk();
        $data = json_decode(DB::table('staff_audit_logs')->where('action', 'settings.updated')->first()->metadata, true);
        $this->assertEquals(14, $data['before']['settings']['default_tax_rate']);
        $this->assertEquals(21, $data['after']['settings']['default_tax_rate']);
    }

    public function test_optimistic_preconditions_are_required_and_expected_values_are_typed(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $role = Role::create(['name' => 'Versioned', 'guard_name' => 'staff']);
        $token = $admin->createToken('raw')->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->id, ['name' => 'Stale'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->withToken($token)->patchJson('/api/v1/admin/roles/'.$role->id, ['permissions' => []])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->withToken($token)->deleteJson('/api/v1/admin/users/'.$target->id)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['default_tax_rate' => 20])->assertUnprocessable()->assertJsonValidationErrors('expected');
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['default_tax_rate' => 20, 'expected' => ['default_tax_rate' => []]])->assertUnprocessable();
    }

    public function test_identical_roles_in_another_order_do_not_revoke_tokens_but_real_changes_do(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $a = Role::create(['name' => 'A role', 'guard_name' => 'staff']);
        $b = Role::create(['name' => 'B role', 'guard_name' => 'staff']);
        $target->assignRole($a, $b);
        $target->createToken('session');
        $this->api($admin, 'PATCH', '/users/'.$target->id, ['roles' => [$b->name, $a->name]])->assertOk();
        $this->assertSame(1, $target->tokens()->count());
        $this->api($admin, 'PATCH', '/users/'.$target->id, ['roles' => [$a->name]])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_repeated_deactivation_is_idempotent_and_does_not_duplicate_audit_entries(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->api($admin, 'DELETE', '/users/'.$target->id, ['expected_version' => 0])->assertOk();
        $this->api($admin, 'DELETE', '/users/'.$target->id, ['expected_version' => 0])->assertOk();
        $this->assertSame(1, (int) $target->fresh()->lock_version);
        $this->assertSame(1, DB::table('staff_audit_logs')->where('action', 'user.deactivated')->where('target_id', $target->id)->count());
    }

    public function test_current_password_guessing_has_a_per_account_budget(): void
    {
        config(['auth.staff_current_password_failures' => 2]);
        $user = $this->admin();
        $user->update(['password' => 'Original-password12']);
        for ($i = 0; $i < 2; $i++) {
            $this->api($user, 'PATCH', '/users/'.$user->id, ['password' => 'Replacement-password12', 'current_password' => 'wrong'])->assertUnprocessable();
        }
        $this->api($user, 'PATCH', '/users/'.$user->id, ['password' => 'Replacement-password12', 'current_password' => 'Original-password12'])->assertStatus(429);
        $this->assertTrue(Hash::check('Original-password12', $user->fresh()->password));
    }

    public function test_denial_logging_failure_does_not_replace_the_original_forbidden_response(): void
    {
        $actor = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $actor->givePermissionTo(Permission::findOrCreate('roles.manage', 'staff'));
        DB::statement('DROP TABLE staff_audit_logs');
        $this->api($actor, 'POST', '/roles', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_denied_mutations_are_throttled_before_an_audit_flood(): void
    {
        config(['auth.staff_admin_mutations_minute' => 2]);
        $actor = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $actor->givePermissionTo(Permission::findOrCreate('roles.manage', 'staff'));
        $this->api($actor, 'POST', '/roles', ['name' => 'Denied'])->assertForbidden();
        $this->api($actor, 'POST', '/roles', ['name' => 'Denied'])->assertForbidden();
        $this->api($actor, 'POST', '/roles', ['name' => 'Denied'])->assertStatus(429);
        $this->assertSame(2, DB::table('staff_audit_logs')->where('action', 'administration.denied')->count());
    }

    public function test_audit_retention_prunes_only_expired_entries(): void
    {
        foreach ([now()->subDays(400), now()->subDays(5)] as $time) {
            DB::table('staff_audit_logs')->insert(['actor_id' => null, 'action' => 'test', 'target_type' => 'settings', 'metadata' => '{}', 'created_at' => $time]);
        }
        $this->artisan('staff:prune-audit-logs')->assertSuccessful();
        $this->assertSame(1, DB::table('staff_audit_logs')->count());
    }

    public function test_role_names_are_unique_ignoring_case_and_missing_system_identity_never_impersonates_an_admin(): void
    {
        $admin = $this->admin();
        $this->api($admin, 'POST', '/roles', ['name' => 'Manager'])->assertCreated();
        $this->api($admin, 'POST', '/roles', ['name' => 'manager'])->assertUnprocessable()->assertJsonValidationErrors('name');
        User::query()->where('is_system', true)->delete();
        $service = app(OrderService::class);
        $method = new \ReflectionMethod($service, 'inventoryActorId');
        $this->expectException(\LogicException::class);
        $method->invoke($service, null);
    }

    public function test_long_lived_queue_workers_clear_their_in_memory_permission_collection(): void
    {
        $this->assertTrue(config('permission.register_octane_reset_listener'));
        Permission::query()->delete();
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        Permission::create(['name' => 'first.permission', 'guard_name' => 'staff']);
        $this->assertCount(1, $registrar->getPermissions());
        DB::table('permissions')->insert(['name' => 'second.permission', 'guard_name' => 'staff']);
        Cache::forget(config('permission.cache.key'));
        $this->assertCount(1, $registrar->getPermissions());
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('database', $job));
        $this->assertCount(2, $registrar->getPermissions());
    }

    public function test_historical_staff_tokens_are_capped_without_shortening_customer_tokens(): void
    {
        $migration = require database_path('migrations/2026_10_25_000005_harden_staff_sessions_and_audit.php');
        $migration->down();
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $staffToken = $user->createToken('historical', ['*'], now()->addDay())->accessToken;
        $staffToken->forceFill(['created_at' => now()->subHours(3)])->save();
        $customerToken = $customer->createToken('customer', ['*'], now()->addDay())->accessToken;
        $customerExpiry = $customerToken->expires_at->toISOString();
        $migration->up();
        $this->assertTrue($staffToken->fresh()->expires_at->isPast());
        $this->assertSame($customerExpiry, $customerToken->fresh()->expires_at->toISOString());
        $this->assertTrue(DB::table('pg_indexes')->where('indexname', 'staff_audit_logs_actor_id_created_at_index')->exists());
    }

    public function test_case_insensitive_role_uniqueness_is_enforced_by_the_database(): void
    {
        Role::create(['name' => 'Manager', 'guard_name' => 'staff']);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => Role::create(['name' => 'manager', 'guard_name' => 'staff']));
    }

    private function wrong(UnifiedAuthService $auth, string $identifier): void
    {
        try {
            $auth->attempt($identifier, 'wrong-password');
            $this->fail('Wrong password accepted');
        } catch (BusinessException|TooManyRequestsHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
    }

    private function api(User $actor, string $method, string $path, array $body = [])
    {
        Auth::forgetGuards();

        return $this->withToken($actor->createToken('review')->plainTextToken)->json($method, '/api/v1/admin'.$path,
            $this->expectedStaffState($method, '/api/v1/admin'.$path, $body));
    }
}
