<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Auth\UnifiedAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class RemainingAuthRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_credentials_survive_the_daily_budget_without_resetting_failed_guess_counters(): void
    {
        config([
            'auth.unified_login_failures_hour' => 10,
            'auth.unified_login_identifier_failures_hour' => 30,
            'auth.unified_login_failures_day' => 2,
        ]);
        $staff = User::factory()->create(['password' => 'Good-password12']);
        $auth = app(UnifiedAuthService::class);
        $this->wrongPassword($auth, $staff->email);
        $this->wrongPassword($auth, $staff->email);

        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);

        request()->server->set('REMOTE_ADDR', '192.0.2.44');
        $this->expectException(TooManyRequestsHttpException::class);
        $auth->attempt($staff->email, 'wrong-password');
    }

    public function test_the_hourly_identifier_guard_accepts_valid_credentials_and_rejects_bad_guesses_across_ips(): void
    {
        config([
            'auth.unified_login_failures_hour' => 10,
            'auth.unified_login_identifier_failures_hour' => 2,
            'auth.unified_login_failures_day' => 100,
        ]);
        $staff = User::factory()->create(['password' => 'Good-password12']);
        $auth = app(UnifiedAuthService::class);
        foreach (['192.0.2.1', '192.0.2.2'] as $ip) {
            request()->server->set('REMOTE_ADDR', $ip);
            $this->wrongPassword($auth, $staff->email);
        }

        request()->server->set('REMOTE_ADDR', '192.0.2.3');
        $this->assertSame($staff->id, $auth->attempt($staff->email, 'Good-password12')->id);
        $this->expectException(TooManyRequestsHttpException::class);
        $auth->attempt($staff->email, 'wrong-password');
    }

    private function wrongPassword(UnifiedAuthService $auth, string $identifier): void
    {
        try {
            $auth->attempt($identifier, 'wrong-password');
            $this->fail('A wrong password was accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(401, $exception->status);
        }
    }
}
