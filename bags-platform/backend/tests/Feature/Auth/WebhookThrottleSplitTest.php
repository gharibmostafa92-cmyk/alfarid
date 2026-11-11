<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebhookThrottleSplitTest extends TestCase
{
    public function test_webhook_routes_use_purpose_specific_limiters(): void
    {
        $expected = [
            'webhooks.payment' => 'throttle:webhooks-payment',
            'webhooks.shipping' => 'throttle:webhooks-shipping',
            'webhooks.whatsapp.inbound' => 'throttle:webhooks-whatsapp',
            'webhooks.whatsapp.customers' => 'throttle:webhooks-whatsapp',
        ];

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'webhooks.')) {
                $routes[$name] = $route;
            }
        }

        $offenders = [];
        foreach ($expected as $name => $limiter) {
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

        $this->assertSame([], $offenders, 'Webhook surfaces need isolated throttle buckets.');
    }
}
