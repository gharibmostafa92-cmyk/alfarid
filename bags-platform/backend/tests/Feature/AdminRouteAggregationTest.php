<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteAggregationTest extends TestCase
{
    public function test_admin_routes_are_loaded_with_expected_names_and_security_middleware(): void
    {
        $routes = Route::getRoutes();

        foreach ([
            'admin.auth.login',
            'admin.auth.me',
            'admin.branches.index',
            'admin.branches.locations.index',
            'admin.users.index',
            'admin.roles.index',
            'admin.permissions.index',
            'admin.settings.index',
            'admin.settings.update',
        ] as $routeName) {
            $this->assertNotNull($routes->getByName($routeName), "Expected [{$routeName}] to be registered.");
        }

        $login = $routes->getByName('admin.auth.login');
        $this->assertContains('throttle:5,1', $login->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $login->gatherMiddleware());

        foreach ([
            'admin.auth.me',
            'admin.branches.index',
            'admin.branches.locations.index',
            'admin.users.index',
            'admin.roles.index',
            'admin.permissions.index',
            'admin.settings.index',
            'admin.settings.update',
        ] as $routeName) {
            $middleware = $routes->getByName($routeName)->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "Expected [{$routeName}] to require a Sanctum token.");
            $this->assertContains('auth.staff', $middleware, "Expected [{$routeName}] to require staff authentication.");
        }

        $this->assertContains(
            'permission:settings.update|settings.manage',
            $routes->getByName('admin.settings.update')->gatherMiddleware(),
        );
    }
}
