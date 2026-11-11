<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteThrottleHygieneTest extends TestCase
{
    public function test_no_api_route_uses_inline_numeric_throttle(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^throttle:\d/', $middleware) === 1) {
                    $offenders[] = ($route->getName() ?? implode('|', $route->methods()).' '.$route->uri()).' => '.$middleware;
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Inline numeric throttles share one bucket across routes and user types; use named limiters instead.'
        );
    }
}
