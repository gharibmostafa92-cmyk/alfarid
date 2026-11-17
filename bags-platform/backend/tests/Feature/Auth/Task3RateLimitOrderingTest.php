<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

// task3 MEDIUM-01: the atomic pair-slot reservation must run before the
// Turnstile challenge, so failed challenges also consume the hard ceiling.
class Task3RateLimitOrderingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function login(array $body)
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', $body);
    }

    public function test_failed_challenges_consume_the_hard_pair_ceiling(): void
    {
        config(['auth.unified_login_failures_hour' => 2, 'services.turnstile.secret_key' => 'test-secret']);
        $email = 'edge-case@example.test';

        // The route-level throttle allows 5/minute per identifier: step over
        // minute windows so only the service-level pair bucket binds.
        $attempt = fn () => $this->login(['login' => $email, 'password' => 'wrong']);

        // Soft limit is 2: the first two failures are plain 401s.
        $attempt()->assertUnauthorized();
        $attempt()->assertUnauthorized();

        // Past the soft limit every attempt demands a challenge (8x).
        for ($i = 0; $i < 3; $i++) {
            $attempt()->assertStatus(429)->assertJsonPath('code', 'challenge_required');
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 5; $i++) {
            $attempt()->assertStatus(429)->assertJsonPath('code', 'challenge_required');
        }
        $this->travel(61)->seconds();

        // The hard ceiling (5x = 10) is now exhausted by the failed
        // challenges themselves: the next attempt is hard-throttled
        // without reaching the challenge validator at all.
        $attempt()->assertStatus(429)->assertJsonMissingPath('code');
    }
}
