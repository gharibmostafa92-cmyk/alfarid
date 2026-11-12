<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * H2: every mutating admin route must carry route-level authorization
 * (permission:/can:/Authorize). Controller-only checks are invisible to
 * review and silently lost when actions are refactored.
 */
class AdminRouteAuthorizationTest extends TestCase
{
    /**
     * Self-service routes whose authorization is the authenticated identity
     * itself (own token, own password, own notifications). Pre-auth login is
     * covered by throttles, not permissions.
     */
    private const SELF_SERVICE_URIS = [
        'api/v1/admin/auth/login',
        'api/v1/admin/auth/logout',
        'api/v1/admin/me/password',
        'api/v1/admin/notifications/{id}/read',
        'api/v1/admin/notifications/read-all',
    ];

    public function test_admin_mutating_routes_carry_route_level_authorization(): void
    {
        $violations = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/admin/')) {
                continue;
            }
            $methods = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());
            if ($methods === [] || in_array($uri, self::SELF_SERVICE_URIS, true)) {
                continue;
            }
            $middleware = array_map(
                fn ($m) => is_string($m) ? $m : get_class($m),
                $route->gatherMiddleware()
            );
            $authorized = false;
            foreach ($middleware as $name) {
                if (str_contains($name, 'permission') || str_starts_with($name, 'can:')
                    || str_contains($name, 'Authorize') || str_contains($name, 'can:')) {
                    $authorized = true;
                    break;
                }
            }
            if (! $authorized) {
                $violations[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $violations, "Admin mutating routes without route-level authorization:\n".implode("\n", $violations));
    }
}
