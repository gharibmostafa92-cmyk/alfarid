<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Jobs\ProcessCustomerWhatsAppMessage;
use App\Models\Customer;
use App\Models\CustomerChannel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerWhatsAppWebhookHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        config([
            'services.whatsapp_business.webhook_secret' => 'cloud-secret',
            'services.whatsapp_business.verify_token' => 'meta-verify-token',
            'services.whatsapp_business.token' => 'meta-bearer-token',
            'services.whatsapp_business.phone_number_id' => '998877',
        ]);
    }

    public function test_meta_webhook_verification_challenge_succeeds_with_matching_token_and_rejects_mismatch(): void
    {
        $ok = $this->get('/api/v1/webhooks/whatsapp/customers?hub.mode=subscribe&hub.verify_token=meta-verify-token&hub.challenge=challenge-123');
        $ok->assertOk();
        $this->assertSame('challenge-123', $ok->getContent());

        $bad = $this->get('/api/v1/webhooks/whatsapp/customers?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123');
        $bad->assertForbidden();
    }

    public function test_oversized_payload_is_rejected_with_413_before_decoding(): void
    {
        $huge = str_repeat('a', 262145);
        $sig = 'sha256='.hash_hmac('sha256', $huge, 'cloud-secret');

        $response = $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $huge);

        $response->assertStatus(413);
    }

    public function test_meta_x_hub_signature_256_is_accepted_and_dispatches_async_job(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'phone' => '01012345678',
            'whatsapp_opt_in_at' => now(),
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => '01012345678',
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.meta.1',
                            'from' => '201012345678',
                            'type' => 'text',
                            'text' => ['body' => 'رصيد'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $sig = 'sha256='.hash_hmac('sha256', $body, 'cloud-secret');
        $response = $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body);

        $response->assertOk()->assertJsonPath('ok', true);
        Queue::assertPushed(ProcessCustomerWhatsAppMessage::class, fn ($job) => $job->connection === 'whatsapp');
    }

    public function test_queue_dispatch_failure_rolls_back_message_deduplication_row(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'phone' => '01012345678',
            'whatsapp_opt_in_at' => now(),
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => '01012345678',
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.meta.retry',
                            'from' => '201012345678',
                            'type' => 'text',
                            'text' => ['body' => 'رصيد'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'cloud-secret');
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $first = $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
        $first->assertServerError();
        $this->assertDatabaseMissing('inbound_messages', ['provider_message_id' => 'wamid.meta.retry']);

        $retryDispatcher = \Mockery::mock(Dispatcher::class);
        $retryDispatcher->shouldReceive('dispatch')->once()->with(\Mockery::type(ProcessCustomerWhatsAppMessage::class));
        $this->app->instance(Dispatcher::class, $retryDispatcher);
        $retry = $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
        $retry->assertOk()->assertJsonPath('queued', true);
    }

    public function test_download_media_blocks_path_traversal_and_ssrf_and_oversized_streams(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/12345' => Http::response([
                'url' => 'https://evil.example.com/steal-token',
            ], 200),
            'https://graph.facebook.com/v21.0/67890' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=67890',
            ], 200),
            'https://lookaside.fbsbx.com/*' => Http::response(
                Utils::streamFor(str_repeat('x', 200)),
                200
            ),
        ]);

        config(['services.gemini.max_image_bytes' => 100]);

        $job = new ProcessCustomerWhatsAppMessage(1, 1, [
            'id' => 'wamid.media',
            'from' => '201012345678',
            'text' => '',
            'button_id' => null,
            'kind' => 'image',
            'media_id' => '../me',
        ], '+201012345678');

        $this->assertNull($job->downloadMedia('../me'));
        Http::assertNothingSent();

        // SSRF host must be rejected before sending Bearer token to evil.example.com
        $this->assertNull($job->downloadMedia('12345'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example.com'));

        // Oversized stream from allowed CDN must be rejected
        $this->assertNull($job->downloadMedia('67890'));
    }
}
