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

    /**
     * Close every non-terminal row. OTP rows are wiped of secrets; other
     * templates keep their payload for diagnosis. Rows already sent stay
     * untouched even if the worker fails after delivery.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = mb_substr($exception?->getMessage() ?? 'job_failed', 0, 180);
        OutboundMessage::query()->whereKey($this->messageId)
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'error', 'error' => $reason]);
        OutboundMessage::query()->whereKey($this->messageId)
            ->where('template', 'customer_otp')->where('status', 'error')
            ->update(['error' => 'otp_delivery_failed', 'payload' => '[]']);
    }

    public function handle(SettingService $settings): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        // Atomic pending -> sending claim (compare-and-swap on the row): a
        // second worker that lost the WithoutOverlapping lock (cache restart,
        // expired lock) can no longer enter the network section for the same
        // message, so Meta never receives it twice from two workers.
        $claimed = OutboundMessage::query()->whereKey($this->messageId)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);
        if ($claimed === 0) {
            return;
        }
        $message->refresh();

        if ($message->scheduled_for !== null && $message->scheduled_for->isFuture()) {
            // Carbon 3 reports signed differences (now - scheduled_for), so the
            // old diffInSeconds() released immediately. Hand the DateTime itself
            // to the queue so "future" always means future. Re-dispatch as a
            // fresh job instead of release(): every release() consumes one of
            // the $tries attempts, so repeated deferrals killed the message
            // before its scheduled time.
            $message->update(['status' => 'pending']);
            $this->defer($message->scheduled_for);

            return;
        }

        $otp = $message->template === 'customer_otp';
        $redacted = $otp ? ['payload' => []] : [];

        if ($settings->getBool('ai_wholesale_shadow_mode', true)) {
            $message->update(['status' => 'shadow', 'error' => null, ...$redacted]);

            return;
        }

        $parameters = $message->payload ?? [];
        if ($otp) {
            // F3: the number under verification lives on the channel row, not
            // on the customer (whatsapp_phone is only set after verification).
            // Deriving the destination from the owned channel keeps OTPs
            // deliverable while the ownership/expiry checks keep them safe.
            $channel = CustomerChannel::query()->where('customer_id', $message->customer_id)
                ->where('provider', 'whatsapp_cloud')->find($parameters['channel_id'] ?? null);
            if (! is_string($parameters['code'] ?? null) || $channel === null
                || $channel->verification_code_hash === null
                || ! $channel->verification_expires_at?->isFuture()
                || $channel->verification_expires_at->timestamp !== ($parameters['expires_at'] ?? null)) {
                $message->update(['status' => 'skipped', 'error' => 'otp_no_longer_current', 'payload' => []]);

                return;
            }
            $destination = CustomerChannel::canonicalPhone((string) $channel->phone);
            if ($destination === null) {
                $message->update(['status' => 'skipped', 'error' => 'no_channel_destination', 'payload' => []]);

                return;
            }
            $parameters = [$parameters['code']];
        } else {
            $message->loadMissing('customer');
            $destination = CustomerChannel::canonicalPhone((string) ($message->customer?->whatsapp_phone ?: $message->customer?->phone));
            if ($destination === null) {
                $message->update(['status' => 'skipped', 'error' => 'no_customer_destination', ...$redacted]);

                return;
            }
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
            // Split connect vs read timeouts: a read timeout fires only after
            // Meta accepted the request, so a retry would double-send. A
            // short connect timeout keeps pre-send failures fast while the
            // longer read window tolerates slow Graph responses.
            $response = Http::withToken($token)
                ->connectTimeout(5)->timeout(20)
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

    /**
     * Re-dispatch a scheduled message as a brand-new job: release() burns one
     * of the $tries attempts per deferral, while a fresh dispatch resets the
     * attempt budget. Guarded for direct handle() invocations (tests/seeds)
     * where no queue worker owns this job.
     */
    protected function defer(\DateTimeInterface $at): void
    {
        if ($this->job !== null) {
            $this->delete();
        }
        self::dispatch($this->messageId)->delay($at);
    }
}
