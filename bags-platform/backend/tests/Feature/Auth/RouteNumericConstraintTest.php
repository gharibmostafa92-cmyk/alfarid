<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteNumericConstraintTest extends TestCase
{
    public function test_no_api_route_uses_unbounded_numeric_constraint(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->wheres as $param => $pattern) {
                if ($pattern === '[0-9]+') {
                    $offenders[] = ($route->getName() ?? $route->uri()).' {'.$param.'}';
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Unbounded [0-9]+ IDs overflow bigint (PG 22003) as 500s; constrain numeric IDs to [0-9]{1,18}.'
        );
    }
}
