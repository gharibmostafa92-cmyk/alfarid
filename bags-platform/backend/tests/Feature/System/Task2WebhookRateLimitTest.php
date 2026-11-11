<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** task2 H3/H4/M6: webhook and heavy-endpoint buckets stay isolated and bounded. */
class Task2WebhookRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_unknown_payment_gateway_slugs_share_the_per_ip_ceiling(): void
    {
        config([
            'webhooks.rate_limits.payment_per_minute' => 100,
            'webhooks.rate_limits.payment_ip_per_minute' => 2,
        ]);

        $this->postJson('/api/v1/webhooks/payment/gateway-one', [])->assertNotFound();
        $this->postJson('/api/v1/webhooks/payment/gateway-two', [])->assertNotFound();
        $this->postJson('/api/v1/webhooks/payment/gateway-three', [])->assertStatus(429);
    }

    public function test_unknown_payment_gateways_share_one_inner_bucket(): void
    {
        config([
            'webhooks.rate_limits.payment_per_minute' => 2,
            'webhooks.rate_limits.payment_ip_per_minute' => 100,
        ]);

        $this->postJson('/api/v1/webhooks/payment/alpha', [])->assertNotFound();
        $this->postJson('/api/v1/webhooks/payment/beta', [])->assertNotFound();
        $this->postJson('/api/v1/webhooks/payment/gamma', [])->assertStatus(429);
    }

    public function test_whatsapp_inbound_and_customer_webhooks_have_separate_buckets(): void
    {
        config([
            'webhooks.rate_limits.whatsapp_inbound_per_minute' => 1,
            'webhooks.rate_limits.whatsapp_customers_per_minute' => 2,
        ]);

        $this->postJson('/api/v1/webhooks/whatsapp/inbound', [])->assertStatus(401);
        $this->postJson('/api/v1/webhooks/whatsapp/inbound', [])->assertStatus(429);
        // Customers traffic is keyed by route, so a gateway flood cannot stall it.
        $this->postJson('/api/v1/webhooks/whatsapp/customers', [])->assertStatus(401);
    }

    public function test_trusted_whatsapp_gateway_gets_a_larger_bounded_allowance(): void
    {
        config([
            'services.whatsapp_gateway.secret' => 'gateway-secret',
            'webhooks.rate_limits.whatsapp_inbound_per_minute' => 1,
            'webhooks.rate_limits.whatsapp_internal_per_minute' => 3,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('X-WA-Gateway-Secret', 'gateway-secret')
                ->postJson('/api/v1/webhooks/whatsapp/inbound', [])
                ->assertStatus(401);
        }

        $this->withHeader('X-WA-Gateway-Secret', 'gateway-secret')
            ->postJson('/api/v1/webhooks/whatsapp/inbound', [])
            ->assertStatus(429);
    }

    public function test_whatsapp_gateway_header_with_wrong_secret_gets_the_normal_limit(): void
    {
        config([
            'services.whatsapp_gateway.secret' => 'gateway-secret',
            'webhooks.rate_limits.whatsapp_inbound_per_minute' => 1,
            'webhooks.rate_limits.whatsapp_internal_per_minute' => 10,
        ]);

        $this->withHeader('X-WA-Gateway-Secret', 'wrong-secret')
            ->postJson('/api/v1/webhooks/whatsapp/inbound', [])
            ->assertStatus(401);
        $this->withHeader('X-WA-Gateway-Secret', 'wrong-secret')
            ->postJson('/api/v1/webhooks/whatsapp/inbound', [])
            ->assertStatus(429);
    }
}
