<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Tests\TestCase;
use App\Support\SentryTelemetry;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Illuminate\Http\Request;
use RuntimeException;

class SentryTelemetryTest extends TestCase
{
    public function test_sentry_uses_sampled_release_traces_without_default_pii_or_logs(): void
    {
        $this->assertSame(0.05, config('sentry.traces_sample_rate'));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.enable_logs'));
        $this->assertSame(1.0, config('sentry.sample_rate'));
        $this->assertSame(1, config('sentry.http_connect_timeout'));
        $this->assertSame(2, config('sentry.http_timeout'));
        $this->assertNull(config('sentry.dsn'));
        $this->assertSame('api', config('services.sentry.service'));
    }

    public function test_event_sanitizer_keeps_only_safe_route_trace_and_stack_metadata(): void
    {
        $this->app['config']->set('services.sentry.service', 'api');
        $frame = new Frame('handle', base_path('app/Http/Controllers/Example.php'), 42, null, null, [
            'token' => 'SENTRY_TEST_TOKEN',
        ]);
        $frame->setContextLine('$phone = "SENTRY_TEST_PHONE";');

        $exception = new ExceptionDataBag(new RuntimeException('SENTRY_TEST_SQL SENTRY_TEST_PROMPT'));
        $exception->setStacktrace(new Stacktrace([$frame]));

        $event = Event::createEvent()
            ->setMessage('SENTRY_TEST_PROMPT')
            ->setRequest([
                'method' => 'POST',
                'url' => 'https://example.test/api/v1/orders/23?phone=SENTRY_TEST_PHONE',
                'headers' => ['authorization' => 'SENTRY_TEST_TOKEN'],
                'data' => ['prompt' => 'SENTRY_TEST_PROMPT'],
            ])
            ->setExtra(['payload' => 'SENTRY_TEST_PROMPT'])
            ->setTags(['service' => 'api', 'secret' => 'SENTRY_TEST_TOKEN'])
            ->setContext('trace', [
                'trace_id' => '0123456789abcdef0123456789abcdef',
                'span_id' => '0123456789abcdef',
                'prompt' => 'SENTRY_TEST_PROMPT',
            ])
            ->setContext('customer', ['phone' => 'SENTRY_TEST_PHONE'])
            ->setExceptions([$exception]);

        $sanitized = SentryTelemetry::sanitizeEvent($event);

        $this->assertNotNull($sanitized);
        $this->assertSame('Application error', $sanitized->getMessage());
        $this->assertSame(['service' => 'api'], $sanitized->getTags());
        $this->assertSame([], $sanitized->getExtra());
        $this->assertSame(['method' => 'POST'], $sanitized->getRequest());
        $this->assertSame([
            'trace_id' => '0123456789abcdef0123456789abcdef',
            'span_id' => '0123456789abcdef',
        ], $sanitized->getContexts()['trace']);
        $this->assertArrayNotHasKey('customer', $sanitized->getContexts());
        $this->assertSame('RuntimeException', $sanitized->getExceptions()[0]->getValue());
        $this->assertSame([], $sanitized->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars());
        $this->assertNull($sanitized->getExceptions()[0]->getStacktrace()->getFrames()[0]->getContextLine());

        $serialized = serialize($sanitized);
        foreach (['SENTRY_TEST_TOKEN', 'SENTRY_TEST_SQL', 'SENTRY_TEST_PHONE', 'SENTRY_TEST_PROMPT'] as $marker) {
            $this->assertStringNotContainsString($marker, $serialized);
        }
    }

    public function test_trace_sampling_uses_local_rate_and_skips_health_and_webhook_routes(): void
    {
        $this->app['config']->set('sentry.traces_sample_rate', 0.05);
        $context = SamplingContext::getDefault(new TransactionContext('api.request', true));
        $this->assertSame(0.05, SentryTelemetry::sampleTrace($context));

        $this->app->instance('request', Request::create('/up', 'GET'));
        $this->assertSame(0.0, SentryTelemetry::sampleTrace($context));

        $this->app->instance('request', Request::create('/api/v1/webhooks/orders', 'POST'));
        $this->assertSame(0.0, SentryTelemetry::sampleTrace($context));
    }

    public function test_expected_health_and_client_failures_are_not_captured_as_server_errors(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/api/v1/route-that-does-not-exist')->assertNotFound();
    }
}
