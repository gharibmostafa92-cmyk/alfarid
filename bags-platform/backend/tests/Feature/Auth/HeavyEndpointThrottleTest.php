<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HeavyEndpointThrottleTest extends TestCase
{
    /** @var array<string, string> */
    private const EXPECTED = [
        'wholesale.price-list.export' => 'throttle:wholesale-heavy',
        'wholesale.quick-order.import' => 'throttle:wholesale-heavy',
        'wholesale.invoices.pdf' => 'throttle:wholesale-heavy',
        'wholesale.payment-notices.store' => 'throttle:wholesale-heavy',
        'admin.price-lists.import' => 'throttle:admin-heavy',
        'admin.price-lists.export' => 'throttle:admin-heavy',
        'admin.customers.documents.store' => 'throttle:admin-heavy',
        'admin.customers.documents.update' => 'throttle:admin-heavy',
    ];

    public function test_cpu_heavy_endpoints_carry_dedicated_throttles(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name)) {
                $routes[$name] = $route;
            }
        }

        $offenders = [];
        foreach (self::EXPECTED as $name => $limiter) {
            if (! isset($routes[$name])) {
                $offenders[] = $name.' (route missing)';
                continue;
            }
            $found = false;
            foreach ($routes[$name]->gatherMiddleware() as $middleware) {
                if ($middleware === $limiter) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $offenders[] = $name.' (missing '.$limiter.')';
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, 'CPU-heavy endpoints need dedicated throttle buckets.');
    }
}
