<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HealthReadyThrottleTest extends TestCase
{
    public function test_readiness_probe_is_throttled(): void
    {
        $route = null;
        foreach (Route::getRoutes() as $candidate) {
            if ($candidate->uri() === 'health/ready') {
                $route = $candidate;
                break;
            }
        }

        $this->assertNotNull($route, 'health/ready route missing');

        $throttled = false;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                $throttled = true;
                break;
            }
        }

        $this->assertTrue($throttled, 'DB-hitting readiness probe must carry a throttle.');
    }
}
