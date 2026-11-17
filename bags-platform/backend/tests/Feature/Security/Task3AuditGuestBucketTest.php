<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\AuditStaffAdministration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

// task3 MEDIUM-06: unauthenticated admin-mutation buckets are per-network,
// never one global guest bucket.
class Task3AuditGuestBucketTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_buckets_are_isolated_per_network(): void
    {
        $middleware = app(AuditStaffAdministration::class);
        $next = fn (): \Illuminate\Http\JsonResponse => response()->json(['ok' => true]);
        $request = fn (string $ip): Request => Request::create(
            '/api/v1/admin/customers', 'POST', [], [], [],
            ['REMOTE_ADDR' => $ip]
        );

        for ($i = 0; $i < 61; $i++) {
            $middleware->handle($request('198.51.100.7'), $next);
        }

        // The hammering network is throttled, but an unrelated network is not.
        $this->assertSame(429, $middleware->handle($request('198.51.100.7'), $next)->getStatusCode());
        $this->assertSame(200, $middleware->handle($request('198.51.100.200'), $next)->getStatusCode());
    }
}
