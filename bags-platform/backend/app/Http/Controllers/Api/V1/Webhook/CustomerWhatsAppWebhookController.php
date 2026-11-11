<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\InboundMessage;
use App\Services\Shared\WebhookSignatureVerifier;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Inbound WhatsApp Business Cloud API webhook controller for merchant interactions.
 *
 * Handles verification, idempotency deduplication, and media routing
 * to the wholesale AI assistant.
 */
final class CustomerWhatsAppWebhookController extends Controller
{
    public function __construct(
        private WebhookSignatureVerifier $signatures,
        private CustomerWholesaleAssistant $assistant,
    ) {}

    /**
     * Ingest, verify, and process an inbound WhatsApp webhook event.
     *
     * @param  Request  $request  Incoming HTTP webhook request.
     * @return JsonResponse JSON acknowledgement or assistant reply.
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
        // Acknowledge non-message webhook events (e.g., delivery receipts) with 200 OK to stop retries.
        if ($message === null) {
            return response()->json(['ok' => true]);
        }

        // Atomically insert message ID to guard against Meta webhook retries during latency spikes.
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
        $sender = CustomerChannel::canonicalPhone($message['from']);
        $matches = Customer::query()->whereAnyPhone($phone)->get()->filter(
            fn (Customer $customer): bool => $sender !== null
                && CustomerChannel::canonicalPhone((string) ($customer->whatsapp_phone ?: $customer->phone)) === $sender,
        );
        $customer = $matches->count() === 1 ? $matches->first() : null;
        // Acknowledge unlinked senders with 200 OK to prevent webhook replay loops while ignoring untracked traffic.
        if ($customer === null) {
            return response()->json(['ok' => true, 'linked' => false]);
        }
        InboundMessage::query()->where('provider_message_id', $message['id'])->update(['customer_id' => $customer->id]);
        $reply = $this->respond($customer, $message, $sender);

        return response()->json(['ok' => true, 'reply' => $reply]);
    }

    /**
     * Routes inbound message content (text, image, or audio) to appropriate assistant pipelines.
     *
     * @param  array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}  $message
     */
    private function respond(Customer $customer, array $message, string $sender): string
    {
        if (in_array($message['kind'], ['image', 'audio'], true) && $message['media_id'] !== null) {
            if (($verification = $this->assistant->requireVerifiedChannel($customer, sender: $sender)) !== null) {
                return $verification;
            }

            $bytes = $this->downloadMedia($message['media_id']);
            if ($bytes === null) {
                return 'الملف موصلش. ابعته تاني من فضلك.';
            }

            // Write to a temporary file because Gemini Vision and transcription services require a file path.
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
                // Ensure temporary media files are cleaned up even if parsing or inference fails.
                @unlink($path);
            }
        }

        return $this->assistant->reply($customer, $message['text'], $message['button_id'], $sender);
    }

    /**
     * Downloads media bytes from Meta Graph API via two-step URL resolution.
     */
    private function downloadMedia(string $mediaId): ?string
    {
        $token = config('services.whatsapp_business.token');
        if (! is_string($token) || $token === '') {
            return null;
        }
        try {
            // Meta Graph API requires fetching the temporary CDN URL metadata first before downloading the binary.
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

            // Reject payloads exceeding configured size before AI processing to prevent OOM errors.
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
     * Normalizes payload from either test fixtures or nested WhatsApp Cloud API webhook structure.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}|null
     */
    private function message(array $payload): ?array
    {
        // Support flat test payloads directly to facilitate unit and feature test mocking.
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
        // TODO: Support document and PDF attachments once invoice/quotation parsing is extended.
        if (! in_array($kind, ['text', 'image', 'audio'], true)) {
            $kind = 'text';
        }

        return [
            'id' => (string) $entry['id'],
            'from' => (string) $entry['from'],
            // Prefer text body, falling back to image caption when merchants provide order notes with photos.
            'text' => (string) ($entry['text']['body'] ?? $entry['image']['caption'] ?? ''),
            'button_id' => isset($entry['interactive']['button_reply']['id']) ? (string) $entry['interactive']['button_reply']['id'] : null,
            'kind' => $kind,
            'media_id' => $mediaId,
        ];
    }
}
