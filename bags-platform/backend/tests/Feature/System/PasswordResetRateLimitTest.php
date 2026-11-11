<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** M4: per-identifier reset attempts need a daily ceiling, not just per-minute. */
class PasswordResetRateLimitTest extends TestCase
{
    public function test_reset_limiters_include_per_day_identifier_ceilings(): void
    {
        $request = Request::create('/x', 'POST', ['email' => 'Victim@Example.com']);

        $confirm = $this->attemptPairs('customer-reset-confirm', $request);
        $this->assertContains([10, 60], $confirm);
        $this->assertContains([50, 86400], $confirm);

        $reset = $this->attemptPairs('customer-password-reset', $request);
        $this->assertContains([5, 60], $reset);
        $this->assertContains([25, 86400], $reset);
    }

    /** @return list<array{int, int}> */
    private function attemptPairs(string $limiter, Request $request): array
    {
        $limits = call_user_func(RateLimiter::limiter($limiter), $request);

        return array_map(
            fn (Limit $limit): array => [$limit->maxAttempts, $limit->decaySeconds],
            is_array($limits) ? $limits : [$limits]
        );
    }
}
