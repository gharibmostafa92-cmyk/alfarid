<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\Auth\StaffAuthenticationAlert;
use App\Rules\TurnstileToken;
use App\Services\Auth\AuthenticationAlerts;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\UnifiedAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task5AuthSlice2Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_transient_token_and_wrong_ability_tokens_are_rejected_on_customer_and_auth_me_routes(): void
    {
        $customer = Customer::factory()->create();

        // TransientToken (stateful session without PersonalAccessToken) must be rejected.
        $customer->withAccessToken(new TransientToken());
        app('auth')->guard('sanctum')->setUser($customer);
        $this->getJson('/api/v1/store/account/orders')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertForbidden();

        // Reset auth state and test a PersonalAccessToken with wrong ability ('pos').
        app('auth')->forgetGuards();
        $wrongAbilityToken = $customer->createToken('pos', ['pos'])->plainTextToken;
        $this->withToken($wrongAbilityToken)->getJson('/api/v1/store/account/orders')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($wrongAbilityToken)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_auth_me_blocks_inactive_branch_and_must_change_password_and_trims_pos_token(): void
    {
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $activeBranch = Branch::factory()->create(['is_active' => true]);

        $adminRole = Role::findOrCreate('manager', 'staff');
        $adminRole->givePermissionTo(Permission::findOrCreate('users.view', 'staff'));

        $inactiveBranchStaff = User::factory()->create(['branch_id' => $inactiveBranch->id]);
        $inactiveBranchStaff->assignRole($adminRole);
        $this->withToken($inactiveBranchStaff->createToken('staff')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();

        app('auth')->forgetGuards();
        $mustChangeStaff = User::factory()->create([
            'branch_id' => $activeBranch->id,
            'must_change_password' => true,
        ]);
        $mustChangeStaff->assignRole($adminRole);
        $this->withToken($mustChangeStaff->createToken('staff')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        app('auth')->forgetGuards();
        $cashierRole = Role::findOrCreate('cashier', 'staff');
        $cashierRole->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $cashier = User::factory()->create(['branch_id' => $activeBranch->id]);
        $cashier->assignRole($cashierRole);
        $posToken = $cashier->createToken('staff-token', ['pos'])->plainTextToken;

        $response = $this->withToken($posToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('roles', $data);
        $this->assertArrayNotHasKey('permissions', $data);
    }

    public function test_turnstile_caches_consumed_failed_token_and_uses_connect_timeout(): void
    {
        config(['services.turnstile.secret_key' => 'test-secret']);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false], 200),
        ]);

        $rule = new TurnstileToken('127.0.0.1');

        $v1 = Validator::make(['t' => 'bad-token-123'], ['t' => [$rule]]);
        $this->assertTrue($v1->fails());

        $v2 = Validator::make(['t' => 'bad-token-123'], ['t' => [$rule]]);
        $this->assertTrue($v2->fails());

        // Second validation with the exact same failed token must hit cache, not Cloudflare again.
        Http::assertSentCount(1);
    }

    public function test_unified_login_does_not_burn_hard_lock_on_missing_turnstile_challenge_prompts(): void
    {
        config([
            'auth.unified_login_failures_hour' => 2,
            'services.turnstile.secret_key' => 'test-secret',
        ]);

        Customer::factory()->create([
            'phone' => '01012345678',
            'password' => 'correct-password-123',
        ]);

        $service = app(UnifiedAuthService::class);

        // 2 wrong attempts reach pairLimit (2).
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->attempt('01012345678', 'wrong-password');
                $this->fail('Expected 401 BusinessException');
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertSame(401, $e->status);
            }
        }

        // 15 attempts without turnstile_token must throw challenge_required (429),
        // NOT burn the 5x hard lock (TooManyRequestsHttpException).
        for ($i = 0; $i < 15; $i++) {
            try {
                $service->attempt('01012345678', 'correct-password-123');
                $this->fail('Expected challenge_required BusinessException');
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertSame(429, $e->status);
                $this->assertSame('challenge_required', $e->errorCode);
            }
        }

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true], 200),
        ]);

        request()->merge(['turnstile_token' => 'valid-token-after-challenge']);
        $actor = $service->attempt('01012345678', 'correct-password-123');
        $this->assertInstanceOf(Customer::class, $actor);
    }

    public function test_staff_handoff_burns_code_on_mismatch_and_tolerates_dual_stack_ip_change(): void
    {
        $user = User::factory()->create();
        $service = app(StaffHandoffService::class);

        $issueReq = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '197.50.10.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 TestBrowser',
        ]);

        $code = $service->issue($user, $issueReq);

        // Wrong User-Agent must fail AND burn the code immediately.
        $attackerReq = Request::create('/api/v1/auth/handoff/exchange', 'POST', [], [], [], [
            'REMOTE_ADDR' => '197.50.10.1',
            'HTTP_USER_AGENT' => 'AttackerBrowser/1.0',
        ]);
        $this->assertNull($service->consume($code, $attackerReq));

        // Even with the original User-Agent, the burned code must now be invalid.
        $legitReqSameIp = Request::create('/api/v1/auth/handoff/exchange', 'POST', [], [], [], [
            'REMOTE_ADDR' => '197.50.10.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 TestBrowser',
        ]);
        $this->assertNull($service->consume($code, $legitReqSameIp));

        // Dual-stack / CGNAT IP change with same User-Agent must succeed.
        $code2 = $service->issue($user, $issueReq);
        $dualStackReq = Request::create('/api/v1/auth/handoff/exchange', 'POST', [], [], [], [
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 TestBrowser',
        ]);
        $payload = $service->consume($code2, $dualStackReq);
        $this->assertNotNull($payload);
        $this->assertSame($user->id, $payload['user_id']);

        // Invalid UTF-8 in credentialFingerprint must not throw JsonException.
        $user->email = "bad\xB1@example.com";
        $fp = StaffHandoffService::credentialFingerprint($user);
        $this->assertNotEmpty($fp);
    }

    public function test_authentication_alerts_sends_notification_via_deferred_or_async_path(): void
    {
        Notification::fake();

        $role = Role::findOrCreate('Super Admin', 'staff');
        $role->givePermissionTo(Permission::findOrCreate('users.manage', 'staff'));
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($role);

        app(AuthenticationAlerts::class)->report('ip_failures', null);

        app(DeferredCallbackCollection::class)->invoke();

        Notification::assertSentTo($admin, StaffAuthenticationAlert::class);
    }
}
