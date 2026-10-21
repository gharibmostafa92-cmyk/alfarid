<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\Auth\StaffHandoffService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedLoginHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_supplier_login_rejected_when_company_inactive(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => false]);
        $user = SupplierUser::factory()->create(['supplier_id' => $supplier->id, 'password' => 'sup-pass']);

        $this->login(['login' => $user->email, 'password' => 'sup-pass'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_me_returns_destination_subtype_with_separate_customer_type(): void
    {
        $customer = Customer::factory()->wholesale()->create(['password' => 'cust-pass']);
        $token = (string) $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])->json('token');

        $this->authed('get', '/api/v1/auth/me', $token)
            ->assertOk()
            ->assertJsonPath('actor.type', 'customer')
            ->assertJsonPath('actor.subtype', 'wholesale_pending')
            ->assertJsonPath('actor.customer_type', 'wholesale');
    }

    public function test_array_login_returns_validation_error_not_server_error(): void
    {
        $this->login(['login' => ['01012345678'], 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_phone_format_variants_share_one_rate_limit_bucket(): void
    {
        foreach (['01012345678', '010 1234 5678', '+201012345678', '00201012345678'] as $variant) {
            $this->login(['login' => $variant, 'password' => 'wrong']);
        }
        // الخامسة مسموحة (5/دقيقة)، والسادسة بصيغة مختلفة كانت قبل الإصلاح
        // بعدّاد مستقل فتفلت — الآن تُحجب.
        $this->login(['login' => '01012345678', 'password' => 'wrong'])->assertUnauthorized();
        $this->login(['login' => '+201012345678', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_cashier_with_inactive_branch_is_forbidden(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $cashier = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'staff']));

        $this->login(['login' => $cashier->email, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_customer_login_reports_cart_merge_result(): void
    {
        $customer = Customer::factory()->create(['password' => 'cust-pass']);

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])
            ->assertOk()
            ->assertJsonPath('cart_merged', true);
    }

    public function test_supplier_tokens_are_capped(): void
    {
        config()->set('auth.supplier_max_tokens', 2);
        $user = SupplierUser::factory()->create(['password' => 'sup-pass']);

        for ($i = 0; $i < 3; $i++) {
            $this->login(['login' => $user->email, 'password' => 'sup-pass'])->assertOk();
        }

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_rejected_retail_customer_goes_to_application_status(): void
    {
        $customer = Customer::factory()->create([
            'password' => 'cust-pass', 'status' => CustomerStatus::Rejected,
            'rejection_reason' => 'بيانات ناقصة',
        ]);

        $response = $this->login(['login' => $customer->phone, 'password' => 'cust-pass']);
        $response->assertOk()
            ->assertJsonPath('redirect.path', '/wholesale/application-status')
            ->assertJsonPath('actor.subtype', 'wholesale_pending');

        $this->authed('get', '/api/v1/wholesale/auth/application-status', (string) $response->json('token'))
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'بيانات ناقصة');
    }

    public function test_exchange_with_empty_sanctum_expiration_keeps_existing_tokens(): void
    {
        // M1: SANCTUM_EXPIRATION الفارغ يعني بلا انتهاء زمني — لا يجوز أن
        // يمسح تبادل الـ handoff كل توكنات الموظف الأخرى (subMinutes(0)).
        config()->set('sanctum.expiration', '');
        $staff = User::factory()->create(['password' => 'staff-pass']);
        $staff->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'staff']));
        $staff->createToken('other-device');
        $staff->createToken('pos-device');
        $code = app(StaffHandoffService::class)->issue($staff);

        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertOk();

        // توكنا الجهازين + توكن الـ exchange الجديد.
        $this->assertSame(3, $staff->tokens()->count());
    }

    public function test_unverified_customer_email_does_not_block_other_account_types(): void
    {
        Customer::factory()->create(['email' => 'pending-cust@example.test', 'email_verified_at' => null]);

        $failed = false;
        (new EmailNotUsedByOtherAccountType('users'))->validate(
            'email', 'pending-cust@example.test', function () use (&$failed): void {
                $failed = true;
            }
        );
        $this->assertFalse($failed);
    }

    public function test_successful_logins_do_not_consume_the_failure_budget(): void
    {
        config()->set('auth.unified_login_failures_hour', 2);
        $customer = Customer::factory()->create(['password' => 'cust-pass']);
        for ($i = 0; $i < 3; $i++) {
            $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])->assertOk();
        }
        for ($i = 0; $i < 2; $i++) {
            $this->login(['login' => $customer->phone, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->travel(61)->seconds();
        $this->login(['login' => '+20'.substr($customer->phone, 1), 'password' => 'cust-pass'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_daily_failure_budget_survives_the_hourly_window(): void
    {
        config()->set('auth.unified_login_failures_day', 2);
        $this->login(['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
        $this->login(['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
        $this->travel(61)->minutes();
        $this->login(['login' => 'UNKNOWN@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->travel(24)->hours();
        $this->login(['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_unknown_email_performs_only_one_password_check_without_generating_a_hash(): void
    {
        Hash::shouldReceive('check')->once()->andReturn(false);
        $this->login(['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_supplier_tokens_survive_an_empty_global_expiration(): void
    {
        config()->set('sanctum.expiration', '');
        $user = SupplierUser::factory()->create(['password' => 'sup-pass']);
        $existing = $user->createToken('other-device')->accessToken;
        $this->login(['login' => $user->email, 'password' => 'sup-pass'])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $existing->id]);
    }

    public function test_supplier_fk_migration_detaches_orphans_and_revokes_their_tokens(): void
    {
        DB::statement('ALTER TABLE supplier_users DROP CONSTRAINT supplier_users_supplier_id_foreign');
        $user = SupplierUser::factory()->create(['supplier_id' => 99999999]);
        $user->createToken('orphan');
        $migration = require database_path('migrations/2026_10_21_000002_restrict_supplier_user_supplier_fk.php');
        $migration->up();
        $this->assertDatabaseHas('supplier_users', ['id' => $user->id, 'supplier_id' => null, 'is_active' => false]);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_customer_cannot_verify_an_email_claimed_by_staff(): void
    {
        $customer = Customer::factory()->create(['email' => 'claimed@example.test', 'email_verified_at' => null]);
        User::factory()->create(['email' => 'claimed@example.test']);
        try {
            $customer->forceFill(['email_verified_at' => now()])->save();
            $this->fail('The shared email must not become a verified customer identity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertNull($customer->fresh()->email_verified_at);
    }

    public function test_model_saves_recheck_cross_account_email_uniqueness(): void
    {
        $supplierUser = SupplierUser::factory()->make(['email' => 'taken@example.test']);
        User::factory()->create(['email' => 'taken@example.test']);
        $this->expectException(ValidationException::class);
        $supplierUser->save();
    }

    public function test_disabled_legacy_login_routes_are_unavailable(): void
    {
        config()->set('auth.legacy_login_enabled', false);
        $router = app('router');
        $original = $router->getRoutes();
        $router->setRoutes(new RouteCollection);
        try {
            foreach (['admin', 'pos', 'store', 'supplier'] as $portal) {
                Route::prefix('api/v1/'.$portal)->middleware('api')->group(base_path('routes/api/'.$portal.'.php'));
                $this->postJson('/api/v1/'.$portal.'/auth/login', [])->assertNotFound();
            }
        } finally {
            $router->setRoutes($original);
        }
    }

    public function test_scheduled_database_cache_cleanup_preserves_live_entries_and_locks(): void
    {
        config()->set('cache.default', 'database');
        DB::table('cache')->insert([
            ['key' => 'expired', 'value' => serialize(1), 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live', 'value' => serialize(1), 'expiration' => now()->addHour()->timestamp],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'expired-lock', 'owner' => 'owner', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live-lock', 'owner' => 'owner', 'expiration' => now()->addHour()->timestamp],
        ]);
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'cache:prune-expired');
        $this->assertNotNull($event);
        $event->run($this->app);
        $this->assertDatabaseMissing('cache', ['key' => 'expired']);
        $this->assertDatabaseMissing('cache_locks', ['key' => 'expired-lock']);
        $this->assertDatabaseHas('cache', ['key' => 'live']);
        $this->assertDatabaseHas('cache_locks', ['key' => 'live-lock']);
    }

    public function test_authentication_audit_retention_preserves_business_audits(): void
    {
        $customer = Customer::factory()->create();
        CustomerAuditLog::record($customer, 'login_failed', null);
        CustomerAuditLog::record($customer, 'business_identity_changed', null);
        DB::table('customer_audit_logs')->update(['created_at' => now()->subDays(91)]);
        CustomerAuditLog::record($customer, 'login_succeeded', null);
        $this->artisan('customers:prune-artifacts')->assertSuccessful();
        $this->assertDatabaseMissing('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'login_failed']);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'login_succeeded']);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'business_identity_changed']);
    }

    public function test_supplier_email_changes_revoke_existing_sessions(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('suppliers.update', 'staff'));
        $staffToken = $staff->createToken('manager')->plainTextToken;
        $user = SupplierUser::factory()->create();
        $user->createToken('old-email-session');
        $this->withToken($staffToken)->putJson('/api/v1/admin/suppliers/'.$user->supplier_id.'/users/'.$user->id, [
            'email' => 'supplier-updated@example.test',
        ])->assertOk();
        $this->assertSame('supplier-updated@example.test', $user->fresh()->email);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_inactive_legacy_staff_identity_does_not_hide_an_active_customer_with_the_same_password(): void
    {
        User::factory()->create(['email' => 'legacy-shared@example.test', 'password' => 'shared-pass', 'is_active' => false]);
        Customer::withoutEvents(fn () => Customer::factory()->create([
            'email' => 'legacy-shared@example.test', 'password' => 'shared-pass',
        ]));
        $this->login(['login' => 'legacy-shared@example.test', 'password' => 'shared-pass'])
            ->assertOk()->assertJsonPath('actor.type', 'customer');
    }

    private function login(array $body)
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', $body);
    }

    private function authed(string $method, string $uri, string $token, array $data = [])
    {
        Auth::forgetGuards();

        return $method === 'get'
            ? $this->getJson($uri, ['Authorization' => 'Bearer '.$token])
            : $this->postJson($uri, $data, ['Authorization' => 'Bearer '.$token]);
    }
}
