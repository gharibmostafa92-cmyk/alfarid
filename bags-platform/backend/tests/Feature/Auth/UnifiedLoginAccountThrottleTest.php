<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnifiedLoginAccountThrottleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unified_login_throttles_same_account_across_ips_per_hour(): void
    {
        config(['auth.unified_login_id_hour' => 2, 'auth.unified_login_id_day' => 100]);

        $login = 'hour-victim@example.test';

        foreach (['10.10.0.1', '10.10.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.3'])
            ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_unified_login_throttles_same_account_across_ips_per_day(): void
    {
        config(['auth.unified_login_id_hour' => 1000, 'auth.unified_login_id_day' => 2]);

        $login = 'day-victim@example.test';

        foreach (['10.20.0.1', '10.20.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.3'])
            ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
