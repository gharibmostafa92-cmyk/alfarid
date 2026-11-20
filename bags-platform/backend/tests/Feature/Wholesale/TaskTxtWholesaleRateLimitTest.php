<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * task.txt item 31: الحماية من الإغراق (rate limiting)
 */
class TaskTxtWholesaleRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_registration_uploads_and_heavy_wholesale_endpoints_are_throttled(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes());
        $middleware = fn (string $method, string $path): array => $routes
            ->first(fn (Route $route): bool => $route->uri() === 'api/v1/wholesale/'.$path
                && in_array($method, $route->methods(), true))
            ?->gatherMiddleware() ?? [];

        $registerMiddleware = $middleware('POST', 'auth/register');
        $docsMiddleware = $middleware('POST', 'auth/documents');

        $this->assertTrue(collect($registerMiddleware)->contains(fn (string $m) => str_contains($m, 'customer-registration')));
        $this->assertTrue(collect($docsMiddleware)->contains(fn (string $m) => str_contains($m, 'customer-registration')));

        // Hit registration endpoint past the customer-registration limiter (default 20 per minute) to prove 429 kicks in
        $limit = (int) config('auth.customer_limits.registration_ip_minute', 20);
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/wholesale/auth/register', []);
        }
        $this->postJson('/api/v1/wholesale/auth/register', [])->assertStatus(429);

        foreach ([['POST', 'payment-notices'], ['POST', 'quick-order/import'], ['GET', 'price-list/export']] as [$method, $path]) {
            $this->assertContains('throttle:wholesale-heavy', $middleware($method, $path));
        }
    }
}
