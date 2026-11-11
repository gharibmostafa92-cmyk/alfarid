<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** task2 H6: an unconfigured proxy warning is emitted once, not per request. */
class Task2ProxyWarningTest extends TestCase
{
    public function test_none_trusted_proxies_with_forwarded_header_warns_once(): void
    {
        config(['app.trusted_proxies' => 'none']);
        Log::spy();

        $this->withHeader('X-Forwarded-For', '203.0.113.9')->get('/up')->assertOk();
        $this->withHeader('X-Forwarded-For', '203.0.113.10')->get('/up')->assertOk();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'TRUSTED_PROXIES'));
    }
}
