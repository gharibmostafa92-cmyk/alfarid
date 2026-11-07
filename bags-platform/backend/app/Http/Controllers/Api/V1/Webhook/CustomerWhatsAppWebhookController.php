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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

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
            'payload' => json_encode(['kind' => $message['kind'], 'text' => $message['text'], 'button_id' => $message['button_id']], JSON_UNESCAPED_UNICODE),
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
        $reply = $this->respond($customer, $message);

        return response()->json(['ok' => true, 'reply' => $reply]);
    }

    /**
     * @param  array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}  $message
     */
    private function respond(Customer $customer, array $message): string
    {
        if (in_array($message['kind'], ['image', 'audio'], true) && $message['media_id'] !== null) {
            $bytes = $this->downloadMedia($message['media_id']);
            if ($bytes === null) {
                return 'الملف موصلش. ابعته تاني من فضلك.';
            }
            $path = tempnam(sys_get_temp_dir(), 'wa-media');
            if ($path === false) {
                return 'الملف موصلش. ابعته تاني من فضلك.';
            }
            file_put_contents($path, $bytes);
            $isImage = $message['kind'] === 'image';
            $file = new UploadedFile($path, $isImage ? 'sheet.jpg' : 'voice.ogg', $isImage ? 'image/jpeg' : 'audio/ogg', null, true);
            try {
                return $isImage
                    ? $this->assistant->readOrderImage($customer, $file)
                    : $this->assistant->transcribeAndReply($customer, $file);
            } finally {
                @unlink($path);
            }
        }

        return $this->assistant->reply($customer, $message['text'], $message['button_id']);
    }

    private function downloadMedia(string $mediaId): ?string
    {
        $token = config('services.whatsapp_business.token');
        if (! is_string($token) || $token === '') {
            return null;
        }
        try {
            $meta = Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$mediaId}");
            $url = $meta->json('url');
            if (! is_string($url) || $url === '') {
                return null;
            }
            $bin = Http::withToken($token)->get($url);
            if (! $bin->successful()) {
                return null;
            }
            $body = $bin->body();
            if ($body === '') {
                return null;
            }
            $max = (int) config('services.gemini.max_image_bytes', 4 * 1024 * 1024);
            if (strlen($body) > $max) {
                return null;
            }

            return $body;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}|null
     */
    private function message(array $payload): ?array
    {
        if (isset($payload['provider_message_id'], $payload['from'])) {
            return [
                'id' => (string) $payload['provider_message_id'],
                'from' => (string) $payload['from'],
                'text' => (string) ($payload['text'] ?? ''),
                'button_id' => isset($payload['button_id']) ? (string) $payload['button_id'] : null,
                'kind' => 'text',
                'media_id' => null,
            ];
        }
        $entry = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        if (! is_array($entry) || ! isset($entry['id'], $entry['from'])) {
            return null;
        }
        $kind = (string) ($entry['type'] ?? 'text');
        $mediaId = null;
        if ($kind === 'image' && isset($entry['image']['id'])) {
            $mediaId = (string) $entry['image']['id'];
        } elseif ($kind === 'audio' && isset($entry['audio']['id'])) {
            $mediaId = (string) $entry['audio']['id'];
        }
        if (! in_array($kind, ['text', 'image', 'audio'], true)) {
            $kind = 'text';
        }

        return [
            'id' => (string) $entry['id'],
            'from' => (string) $entry['from'],
            'text' => (string) ($entry['text']['body'] ?? $entry['image']['caption'] ?? ''),
            'button_id' => isset($entry['interactive']['button_reply']['id']) ? (string) $entry['interactive']['button_reply']['id'] : null,
            'kind' => $kind,
            'media_id' => $mediaId,
        ];
    }
}
