<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CustomerChannel;
use App\Models\OutboundMessage;
use App\Services\Shared\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Dispatches an outbound WhatsApp template message from the outbox queue.
 *
 * In shadow mode, persists the record without calling the Meta Graph API.
 * Marks message as skipped if Cloud API credentials are unconfigured or destination phone is missing.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    /** Transient provider failures are retried with backoff instead of lost. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(public int $messageId) {}

    /**
     * A message is processed by one worker at a time; the scheduled_for path
     * releases and re-enters the queue, so overlap is the normal case.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('outbound-message:'.$this->messageId))->releaseAfter(10)->expireAfter(300)];
    }

    /** Remove OTP secrets even when the queue exhausts all transient retries. */
    public function failed(?Throwable $exception): void
    {
        OutboundMessage::query()->whereKey($this->messageId)
            ->where('template', 'customer_otp')->where('status', 'pending')
            ->update(['status' => 'error', 'error' => 'otp_delivery_failed', 'payload' => '[]']);
    }

    public function handle(SettingService $settings): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->status !== 'pending') {
            return;
        }

        if ($message->scheduled_for !== null && $message->scheduled_for->isFuture()) {
            // Carbon 3 reports signed differences (now - scheduled_for), so the
            // old diffInSeconds() released immediately. Hand the DateTime itself
            // to the queue so "future" always means future.
            $this->release($message->scheduled_for);

            return;
        }

        $otp = $message->template === 'customer_otp';
        $redacted = $otp ? ['payload' => []] : [];

        if ($settings->getBool('ai_wholesale_shadow_mode', true)) {
            $message->update(['status' => 'shadow', 'error' => null, ...$redacted]);

            return;
        }

        $message->loadMissing('customer');
        $destination = CustomerChannel::canonicalPhone((string) ($message->customer?->whatsapp_phone ?: $message->customer?->phone));
        if ($destination === null) {
            $message->update(['status' => 'skipped', 'error' => 'no_customer_destination', ...$redacted]);

            return;
        }

        $parameters = $message->payload ?? [];
        if ($otp) {
            $channel = CustomerChannel::query()->where('customer_id', $message->customer_id)
                ->where('provider', 'whatsapp_cloud')->find($parameters['channel_id'] ?? null);
            if (! is_string($parameters['code'] ?? null) || $channel === null || $channel->phone !== $destination
                || $channel->verification_code_hash === null
                || ! $channel->verification_expires_at?->isFuture()
                || $channel->verification_expires_at->timestamp !== ($parameters['expires_at'] ?? null)) {
                $message->update(['status' => 'skipped', 'error' => 'otp_no_longer_current', 'payload' => []]);

                return;
            }
            $parameters = [$parameters['code']];
        }

        $token = config('services.whatsapp_business.token');
        $phoneId = config('services.whatsapp_business.phone_number_id');
        if (! is_string($token) || $token === '' || ! is_string($phoneId) || $phoneId === '') {
            $message->update([
                'status' => 'skipped',
                'error' => 'provider_not_configured',
                ...$redacted,
            ]);

            return;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($destination, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => (string) $message->template,
                        'language' => ['code' => 'ar'],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => collect($parameters)
                                ->map(fn ($value) => ['type' => 'text', 'text' => (string) $value])
                                ->values()
                                ->all(),
                        ]],
                    ],
                ]);
        } catch (ConnectionException $e) {
            // Timeouts and connection resets are transient: retry with backoff
            // rather than marking the message permanently failed.
            throw $e;
        } catch (Throwable $e) {
            $message->update(['status' => 'error', 'error' => $otp ? 'otp_provider_error' : mb_substr($e->getMessage(), 0, 180), ...$redacted]);

            return;
        }

        if (! $response->successful()) {
            // Meta rate limits and 5xx responses are retryable; 4xx means the
            // request itself is invalid and a retry would fail identically.
            if ($response->status() === 429 || $response->status() >= 500) {
                throw new RuntimeException('whatsapp_transient_http_'.$response->status());
            }

            $message->update(['status' => 'error', 'error' => $otp ? 'otp_provider_error' : mb_substr($response->body(), 0, 180), ...$redacted]);

            return;
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => $response->json('messages.0.id'),
            'error' => null,
            ...$redacted,
        ]);
    }
}
