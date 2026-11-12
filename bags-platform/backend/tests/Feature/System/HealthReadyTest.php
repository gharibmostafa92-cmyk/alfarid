<?php

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthReadyTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_is_healthy_when_all_dependencies_are_available(): void
    {
        Redis::shouldReceive('connection')->with('health')->andReturn(new class
        {
            public function ping(): bool
            {
                return true;
            }
        });
        Cache::put('scheduler_heartbeat_at', now()->toIso8601String(), 600);

        $this->get('/health/ready')->assertOk()
            ->assertJsonPath('status', 'ok')->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('checks.scheduler', 'ok');
    }

    public function test_readiness_fails_when_the_scheduler_heartbeat_is_stale(): void
    {
        // H6: a dead scheduler must page via readiness instead of rotting silently.
        Cache::put('scheduler_heartbeat_at', now()->subMinutes(11)->toIso8601String(), 600);

        $response = $this->get('/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.scheduler', 'fail');
    }

    public function test_readiness_fails_when_the_scheduler_never_reported(): void
    {
        Cache::forget('scheduler_heartbeat_at');

        $this->get('/health/ready')->assertStatus(503)
            ->assertJsonPath('checks.scheduler', 'fail');
    }

    public function test_readiness_returns_service_unavailable_when_redis_fails(): void
    {
        Redis::shouldReceive('connection')->with('health')->andThrow(new \RuntimeException('Redis unavailable'));

        $this->get('/health/ready')->assertStatus(503)
            ->assertJsonPath('status', 'fail')->assertJsonPath('checks.redis', 'fail');
    }

    public function test_readiness_can_skip_redis_for_a_database_only_deployment(): void
    {
        config(['app.readiness_check_redis' => false]);
        Redis::shouldReceive('connection')->never();
        Cache::put('scheduler_heartbeat_at', now()->toIso8601String(), 600);

        $this->get('/health/ready')->assertOk()
            ->assertJsonPath('checks.db', 'ok')
            ->assertJsonPath('checks.redis', 'skipped');
    }

    public function test_health_ready_returns_structured_payload_with_request_id(): void
    {
        $response = $this->get('/health/ready');

        // 200 when db+redis+storage all ok, 503 otherwise (e.g. no Redis in CI).
        $this->assertContains($response->getStatusCode(), [200, 503]);
        $response->assertJsonStructure(['status', 'checks' => ['db', 'redis', 'storage']]);
        $response->assertHeader('X-Request-ID');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_client_request_id_is_not_adopted_without_a_trusted_proxy(): void
    {
        // L8: without a trusted proxy the server mints the correlation id;
        // the client value is kept aside instead of being adopted.
        $response = $this->get('/health/ready', ['X-Request-ID' => 'demo-probe-123']);

        $id = $response->headers->get('X-Request-ID');
        $this->assertIsString($id);
        $this->assertNotSame('demo-probe-123', $id);
    }

    public function test_invalid_request_id_is_replaced_with_uuid(): void
    {
        $response = $this->get('/api/v1/store/branding', ['X-Request-ID' => 'bad id with spaces!!']);

        $response->assertOk();
        $id = $response->headers->get('X-Request-ID');
        $this->assertIsString($id);
        $this->assertNotSame('bad id with spaces!!', $id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $id);
    }

    public function test_api_responses_carry_request_id_header(): void
    {
        $response = $this->get('/api/v1/store/branding');

        $response->assertOk();
        $response->assertHeader('X-Request-ID');
    }
}
