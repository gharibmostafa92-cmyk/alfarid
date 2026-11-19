<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEmail;

use App\Services\CustomerEmail\ResendSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResendSenderTest extends TestCase
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    private const EVENT_KEY = 'customer-email:testing:welcome/42';

    private const BODY = '{"from":"Alfarid <orders@alfarid.example>","to":["customer@example.test"],"subject":"تأكيد الحساب","text":"تم إنشاء حسابك بنجاح."}';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resend.key' => 're_test_fake_token',
            'services.resend.delivery_enabled' => true,
        ]);
    }

    public function test_posts_the_stored_json_once_with_bearer_and_idempotency_headers(): void
    {
        Http::fake(fn (Request $request) => Http::response(['id' => 'b8d8d48f-22ea-4924-a7e0-2937ee9b8332'], 200));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('accepted', $result->kind);
        $this->assertSame('b8d8d48f-22ea-4924-a7e0-2937ee9b8332', $result->providerId);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer re_test_fake_token')
            && $request->hasHeader('Idempotency-Key', self::EVENT_KEY)
            && $request->body() === self::BODY
        );
    }

    public function test_returns_pause_without_a_network_call_when_delivery_is_disabled(): void
    {
        config(['services.resend.delivery_enabled' => false]);
        Http::fake();

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('pause', $result->kind);
        $this->assertSame('delivery_disabled', $result->safeCode);
        Http::assertNothingSent();
    }

    public function test_returns_pause_without_a_network_call_when_the_key_is_missing(): void
    {
        config(['services.resend.key' => null]);
        Http::fake();

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('pause', $result->kind);
        $this->assertSame('resend_not_configured', $result->safeCode);
        Http::assertNothingSent();
    }

    public function test_returns_pause_without_a_network_call_when_the_sender_address_is_invalid(): void
    {
        Http::fake();
        $body = str_replace('Alfarid <orders@alfarid.example>', 'not an address', self::BODY);

        $result = app(ResendSender::class)->send(self::EVENT_KEY, $body);

        $this->assertSame('pause', $result->kind);
        $this->assertSame('resend_sender_configuration', $result->safeCode);
        Http::assertNothingSent();
    }

    public function test_classifies_connection_errors_as_retry_without_exposing_error_text(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('customer@example.test re_test_fake_token private provider response');
        });

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('retry', $result->kind);
        $this->assertSame('network_error', $result->safeCode);
        $this->assertStringNotContainsString('customer@example.test', serialize($result));
        $this->assertStringNotContainsString('re_test_fake_token', serialize($result));
        $this->assertSame(1, $calls);
    }

    public static function retryableAndPauseResponses(): array
    {
        return [
            '408' => [408, ['name' => 'application_error'], 'retry', 'upstream_timeout'],
            'server error' => [503, ['name' => 'service_unavailable'], 'retry', 'upstream_unavailable'],
            'concurrent idempotent request' => [409, ['name' => 'concurrent_idempotent_requests'], 'retry', 'idempotency_in_progress'],
            'daily quota' => [429, ['name' => 'daily_quota_exceeded'], 'pause', 'quota_daily_exceeded'],
            'monthly quota' => [429, ['name' => 'monthly_quota_exceeded'], 'pause', 'quota_monthly_exceeded'],
            'rate limit' => [429, ['name' => 'rate_limit_exceeded'], 'retry', 'rate_limited'],
            'invalid key' => [401, ['name' => 'missing_api_key'], 'pause', 'resend_credentials_rejected'],
            'unverified sender domain' => [403, ['name' => 'validation_error'], 'pause', 'resend_sender_configuration'],
            'unknown endpoint' => [404, ['name' => 'not_found'], 'pause', 'resend_endpoint_configuration'],
            'wrong endpoint method' => [405, ['name' => 'method_not_allowed'], 'pause', 'resend_endpoint_configuration'],
        ];
    }

    #[DataProvider('retryableAndPauseResponses')]
    public function test_classifies_provider_errors_without_retaining_provider_text(int $status, array $error, string $kind, string $safeCode): void
    {
        Http::fake(fn () => Http::response($error + ['message' => 'private@example.test re_test_fake_token raw provider detail'], $status));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame($kind, $result->kind);
        $this->assertSame($safeCode, $result->safeCode);
        $this->assertStringNotContainsString('private@example.test', serialize($result));
        $this->assertStringNotContainsString('re_test_fake_token', serialize($result));
        $this->assertStringNotContainsString('raw provider detail', serialize($result));
    }

    public function test_documented_invalid_api_key_validation_error_pauses_delivery(): void
    {
        Http::fake(fn () => Http::response([
            'name' => 'validation_error',
            'message' => 'API key is invalid',
        ], 400));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('pause', $result->kind);
        $this->assertSame('resend_credentials_rejected', $result->safeCode);
    }

    public static function retryAfterValues(): array
    {
        return [
            'numeric seconds' => ['12', 'retry', 12],
            'missing header' => [null, 'retry', null],
            'not numeric' => ['soon', 'review', null],
            'too large' => ['999999999999999999999', 'review', null],
            'zero is invalid' => ['0', 'review', null],
        ];
    }

    #[DataProvider('retryAfterValues')]
    public function test_rate_limit_retry_after_is_parsed_safely(?string $retryAfter, string $kind, ?int $seconds): void
    {
        Http::fake(function () use ($retryAfter) {
            $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];

            return Http::response(['name' => 'rate_limit_exceeded'], 429, $headers);
        });

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame($kind, $result->kind);
        $this->assertSame($seconds, $result->retryAfterSeconds);
    }

    public function test_invalid_idempotency_payload_mismatch_requires_review(): void
    {
        Http::fake(fn () => Http::response(['name' => 'invalid_idempotent_request'], 409));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('review', $result->kind);
        $this->assertSame('idempotency_payload_mismatch', $result->safeCode);
    }

    public function test_success_without_a_valid_provider_id_requires_review(): void
    {
        Http::fake(fn () => Http::response(['message' => 'accepted without id'], 200));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('review', $result->kind);
        $this->assertSame('provider_id_missing', $result->safeCode);
        $this->assertNull($result->providerId);
    }

    public function test_invalid_recipient_payload_is_rejected_before_network_call(): void
    {
        Http::fake();
        $body = str_replace('customer@example.test', 'customer@example.test,attacker@example.test', self::BODY);

        $result = app(ResendSender::class)->send(self::EVENT_KEY, $body);

        $this->assertSame('permanent', $result->kind);
        $this->assertSame('invalid_message_payload', $result->safeCode);
        Http::assertNothingSent();
    }

    public function test_invalid_event_key_is_rejected_before_network_call(): void
    {
        Http::fake();

        $result = app(ResendSender::class)->send("\r\nBad-Key", self::BODY);

        $this->assertSame('permanent', $result->kind);
        $this->assertSame('invalid_event_key', $result->safeCode);
        Http::assertNothingSent();
    }

    public function test_error_response_content_is_not_logged_or_returned(): void
    {
        Log::spy();
        Http::fake(fn () => Http::response([
            'name' => 'validation_error',
            'message' => 'email customer@example.test failed with re_test_fake_token and private provider response',
        ], 422));

        $result = app(ResendSender::class)->send(self::EVENT_KEY, self::BODY);

        $this->assertSame('permanent', $result->kind);
        $this->assertSame('provider_rejected_message', $result->safeCode);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertStringNotContainsString('customer@example.test', serialize($result));
        $this->assertStringNotContainsString('private provider response', serialize($result));
    }
}
