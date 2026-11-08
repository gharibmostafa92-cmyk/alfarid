<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Customer;
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

    public function test_registration_and_document_upload_routes_carry_throttle_while_portal_notices_and_import_await_owner_decision(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())->keyBy(fn (Route $r) => $r->uri());

        $registerMiddleware = $routes->get('api/v1/wholesale/auth/register')?->gatherMiddleware() ?? [];
        $docsMiddleware = $routes->get('api/v1/wholesale/auth/documents')?->gatherMiddleware() ?? [];

        $this->assertTrue(collect($registerMiddleware)->contains(fn (string $m) => str_contains($m, 'customer-registration')));
        $this->assertTrue(collect($docsMiddleware)->contains(fn (string $m) => str_contains($m, 'customer-registration')));

        // Hit registration endpoint past the customer-registration limiter (default 20 per minute) to prove 429 kicks in
        $limit = (int) config('auth.customer_limits.registration_ip_minute', 20);
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/wholesale/auth/register', []);
        }
        $this->postJson('/api/v1/wholesale/auth/register', [])->assertStatus(429);

        // Documented in task.txt item 31 (owner decision pending):
        // payment-notices and quick-order/import do not currently attach a dedicated route-level throttle.
        $noticesMiddleware = $routes->get('api/v1/wholesale/payment-notices')?->gatherMiddleware() ?? [];
        $importMiddleware = $routes->get('api/v1/wholesale/quick-order/import')?->gatherMiddleware() ?? [];

        $hasDedicatedNoticeThrottle = collect($noticesMiddleware)->contains(fn (string $m) => str_contains($m, 'ThrottleRequests') || str_starts_with($m, 'throttle:'));
        $hasDedicatedImportThrottle = collect($importMiddleware)->contains(fn (string $m) => str_contains($m, 'ThrottleRequests') || str_starts_with($m, 'throttle:'));

        $this->assertFalse($hasDedicatedNoticeThrottle);
        $this->assertFalse($hasDedicatedImportThrottle);
    }
}
