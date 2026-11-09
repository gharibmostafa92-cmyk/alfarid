<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Services\Shared\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
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

    public function __construct(public int $messageId) {}

    public function handle(SettingService $settings): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->status !== 'pending') {
            return;
        }

        if ($message->scheduled_for !== null && $message->scheduled_for->isFuture()) {
            $this->release($message->scheduled_for->diffInSeconds(now()));

            return;
        }

        if ($settings->getBool('ai_wholesale_shadow_mode', true)) {
            $message->update(['status' => 'shadow', 'error' => null]);

            return;
        }

        $message->loadMissing('customer');
        $to = $message->customer?->whatsapp_phone ?: $message->customer?->phone;
        if ($message->customer_id === null || ! is_string($to) || $to === '') {
            $message->update(['status' => 'skipped', 'error' => 'no_customer_destination']);

            return;
        }

        $token = config('services.whatsapp_business.token');
        $phoneId = config('services.whatsapp_business.phone_number_id');
        if (! is_string($token) || $token === '' || ! is_string($phoneId) || $phoneId === '') {
            $message->update([
                'status' => 'skipped',
                'error' => 'provider_not_configured',
            ]);

            return;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => (string) $message->template,
                        'language' => ['code' => 'ar'],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => collect($message->payload ?? [])
                                ->map(fn ($value) => ['type' => 'text', 'text' => (string) $value])
                                ->values()
                                ->all(),
                        ]],
                    ],
                ]);
        } catch (Throwable $e) {
            $message->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 180)]);

            return;
        }

        if (! $response->successful()) {
            $message->update(['status' => 'error', 'error' => mb_substr($response->body(), 0, 180)]);

            return;
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => $response->json('messages.0.id'),
            'error' => null,
        ]);
    }
}
