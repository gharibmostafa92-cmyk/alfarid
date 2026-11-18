<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCustomerWhatsAppMessage;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\InboundMessage;
use App\Services\Shared\WebhookSignatureVerifier;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Inbound WhatsApp Business Cloud API webhook controller for merchant interactions.
 *
 * Handles Meta verification challenges, signature validation, idempotency deduplication,
 * and async queue dispatch for wholesale AI assistant processing.
 */
final class CustomerWhatsAppWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 262144;

    public function __construct(
        private WebhookSignatureVerifier $signatures,
    ) {}

    /**
     * Handle Meta WhatsApp Cloud API webhook verification challenge (GET).
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode') ?? '');
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? '');
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? '');
        $expected = config('services.whatsapp_business.verify_token');

        if (
            $mode === 'subscribe'
            && is_string($expected)
            && $expected !== ''
            && $token !== ''
            && hash_equals($expected, $token)
        ) {
            return response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return response()->json(['message' => 'forbidden'], 403);
    }

    /**
     * Ingest, verify, and queue an inbound WhatsApp webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'payload too large'], 413);
        }

        $secret = config('services.whatsapp_business.webhook_secret');
        $secretStr = is_string($secret) ? $secret : null;
        $ok = $this->signatures->verify($secretStr, $raw, $request->header('X-Hub-Signature-256'))
            || $this->signatures->verifyTimestamped(
                $secretStr,
                $raw,
                $request->header('X-WA-Signature'),
                $request->header('X-WA-Timestamp'),
            );
        if (! $ok) {
            return response()->json(['message' => 'توقيع غير صالح.'], 401);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return response()->json(['ok' => true]);
        }

        $message = $this->message($decoded);
        // Acknowledge non-message webhook events (e.g., delivery receipts) with 200 OK to stop retries.
        if ($message === null) {
            return response()->json(['ok' => true]);
        }

        $sender = CustomerChannel::canonicalPhone($message['from']);
        $customer = $sender === null ? null : $this->customerForSender($sender);
        // Commit the idempotency row and the database-backed queue row together.
        // If enqueueing fails, Meta's retry must be able to insert the message again.
        $receipt = DB::transaction(function () use ($message, $sender, $customer): array {
            $inserted = InboundMessage::query()->insertOrIgnore([
                'provider_message_id' => $message['id'],
                'payload' => json_encode(['kind' => $message['kind'], 'text' => $message['text'], 'button_id' => $message['button_id']], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return ['duplicate' => true];
            }

            $inbound = InboundMessage::query()->where('provider_message_id', $message['id'])->first();
            if ($inbound === null) {
                return [];
            }

            // Acknowledge unlinked senders with 200 OK to prevent webhook replay loops while ignoring untracked traffic.
            if ($customer === null || $sender === null) {
                return ['linked' => false];
            }

            $inbound->forceFill(['customer_id' => $customer->id])->save();
            app(Dispatcher::class)->dispatch(new ProcessCustomerWhatsAppMessage($inbound->id, $customer->id, $message, $sender));

            return ['queued' => true];
        });

        return response()->json(['ok' => true] + $receipt);
    }

    private function customerForSender(string $sender): ?Customer
    {
        // Customer accounts index Egyptian numbers in local form. Convert the
        // sender once and prefer an explicit WhatsApp number over the login phone.
        $phone = Customer::normalizePhone($sender);
        $matches = Customer::query()
            ->where(fn ($query) => $query->where('whatsapp_phone', $phone)
                ->orWhere(fn ($fallback) => $fallback->whereNull('whatsapp_phone')->where('phone', $phone)))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
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
