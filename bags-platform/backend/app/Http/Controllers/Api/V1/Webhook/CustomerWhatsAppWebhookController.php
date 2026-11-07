<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InboundMessage;
use App\Services\WebhookSignatureVerifier;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp Business webhook for merchant communications.
 * Uses dedicated HMAC signature secret separate from staff QR gateway.
 */
final class CustomerWhatsAppWebhookController extends Controller
{
    public function __construct(
        private WebhookSignatureVerifier $signatures,
        private CustomerWholesaleAssistant $assistant,
    ) {}

    /**
     * Ingest and process inbound WhatsApp messages from registered merchants.
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $secret = config('services.whatsapp_business.webhook_secret');
        $ok = $this->signatures->verifyTimestamped(
            is_string($secret) ? $secret : null,
            $raw,
            $request->header('X-WA-Signature'),
            $request->header('X-WA-Timestamp'),
        );
        if (! $ok) {
            return response()->json(['message' => 'توقيع غير صالح.'], 401);
        }

        $message = $this->message($request->json()->all());
        if ($message === null) {
            return response()->json(['ok' => true]);
        }
        $inserted = InboundMessage::query()->insertOrIgnore([
            'provider_message_id' => $message['id'],
            'payload' => json_encode(['text' => $message['text'], 'button_id' => $message['button_id']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $phone = Customer::normalizePhone($message['from']);
        $customer = Customer::query()
            ->where('phone', $phone)
            ->orWhere('whatsapp_phone', $phone)
            ->first();
        if ($customer === null) {
            return response()->json(['ok' => true, 'linked' => false]);
        }
        InboundMessage::query()->where('provider_message_id', $message['id'])->update(['customer_id' => $customer->id]);
        $reply = $this->assistant->reply($customer, $message['text'], $message['button_id']);

        return response()->json(['ok' => true, 'reply' => $reply]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, from: string, text: string, button_id: ?string}|null
     */
    private function message(array $payload): ?array
    {
        if (isset($payload['provider_message_id'], $payload['from'])) {
            return [
                'id' => (string) $payload['provider_message_id'],
                'from' => (string) $payload['from'],
                'text' => (string) ($payload['text'] ?? ''),
                'button_id' => isset($payload['button_id']) ? (string) $payload['button_id'] : null,
            ];
        }
        $entry = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        if (! is_array($entry) || ! isset($entry['id'], $entry['from'])) {
            return null;
        }

        return [
            'id' => (string) $entry['id'],
            'from' => (string) $entry['from'],
            'text' => (string) ($entry['text']['body'] ?? ''),
            'button_id' => isset($entry['interactive']['button_reply']['id']) ? (string) $entry['interactive']['button_reply']['id'] : null,
        ];
    }
}
