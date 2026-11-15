<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Jobs\SendOutboundMessage;
use App\Listeners\Procurement\SendPurchaseOrderSent;
use App\Models\Customer;
use App\Models\OutboundMessage;
use App\Services\Shared\SettingService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** H1/M8: scheduled sends, retries, and post-commit delivery. */
class OutboundMessageQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function message(array $overrides = []): OutboundMessage
    {
        $customer = Customer::factory()->create(['phone' => '201112345678']);

        return OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'customer_whatsapp',
            'idempotency_key' => 'queue-probe-'.uniqid(),
            'status' => 'pending',
            'template' => 'quotation_priced',
            'payload' => ['quotation_number' => 'Q-1'],
            ...$overrides,
        ]);
    }

    private function settings(): SettingService
    {
        $settings = Mockery::mock(SettingService::class);
        $settings->shouldReceive('getBool')->with('ai_wholesale_shadow_mode', true)->andReturn(false);

        return $settings;
    }

    private function jobWith(OutboundMessage $message, ?QueueJob $queueJob = null): SendOutboundMessage
    {
        $job = new SendOutboundMessage($message->id);
        $job->setJob($queueJob ?? Mockery::mock(QueueJob::class));

        return $job;
    }

    private function configureProvider(): void
    {
        config([
            'services.whatsapp_business.token' => 'token',
            'services.whatsapp_business.phone_number_id' => '123',
        ]);
    }

    public function test_a_future_scheduled_message_is_released_for_that_instant(): void
    {
        $message = $this->message(['scheduled_for' => now()->addMinutes(10)]);
        $queueJob = Mockery::mock(QueueJob::class);
        // The queue normalizes the DateTimeInterface to a POSITIVE delay; the
        // old signed diffInSeconds() value released the message immediately.
        $queueJob->shouldReceive('release')->once()->with(Mockery::on(
            fn ($delay) => is_int($delay) && $delay > 0 && $delay <= 600
        ));

        Http::fake();
        $this->jobWith($message, $queueJob)->handle($this->settings());

        Http::assertNothingSent();
        $this->assertSame('pending', $message->fresh()->status);
    }

    public function test_transient_provider_failures_are_retried_not_marked_terminal(): void
    {
        $this->configureProvider();
        Http::fake(['*' => Http::response('unavailable', 503)]);
        $message = $this->message();

        try {
            $this->jobWith($message)->handle($this->settings());
            $this->fail('Transient provider failures must be thrown for a retry.');
        } catch (RuntimeException) {
            // expected: the queue will apply $backoff and retry this job.
        }

        $this->assertSame('pending', $message->fresh()->status);
    }

    public function test_provider_rate_limits_are_retried(): void
    {
        $this->configureProvider();
        Http::fake(['*' => Http::response('slow down', 429)]);
        $message = $this->message();

        $this->expectException(RuntimeException::class);
        $this->jobWith($message)->handle($this->settings());
    }

    public function test_exhausted_otp_retries_redact_the_secret(): void
    {
        $message = $this->message(['template' => 'customer_otp', 'payload' => ['code' => '123456', 'channel_id' => 1]]);
        $this->jobWith($message)->failed(new RuntimeException('provider unavailable'));
        $this->assertSame('error', $message->fresh()->status);
        $this->assertSame([], $message->fresh()->payload);
    }

    public function test_permanent_provider_rejection_marks_the_message_failed(): void
    {
        $this->configureProvider();
        Http::fake(['*' => Http::response('bad request', 400)]);
        $message = $this->message();

        $this->jobWith($message)->handle($this->settings());

        $this->assertSame('error', $message->fresh()->status);
    }

    public function test_retry_budget_is_bounded(): void
    {
        $job = new SendOutboundMessage(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 300, 600], $job->backoff);
    }

    public function test_the_purchase_order_notification_runs_after_commit(): void
    {
        $this->assertTrue((new SendPurchaseOrderSent)->afterCommit);
    }
}
