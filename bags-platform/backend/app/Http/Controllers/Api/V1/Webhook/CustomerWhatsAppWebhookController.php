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

        $messages = $this->messages($decoded);
        // Acknowledge non-message webhook events (e.g., delivery receipts) with 200 OK to stop retries.
        if ($messages === []) {
            return response()->json(['ok' => true]);
        }

        $queuedCount = 0;
        foreach ($messages as $message) {
            $sender = CustomerChannel::canonicalPhone($message['from']);
            $customer = $sender === null ? null : $this->customerForSender($sender);
            // Commit each idempotency row and its queue row together so Meta can retry a failed enqueue.
            $receipt = DB::transaction(function () use ($message, $sender, $customer): array {
                $inserted = InboundMessage::query()->insertOrIgnore([
                    'provider_message_id' => $message['id'],
                    'payload' => json_encode([
                        'kind' => $message['kind'],
                        'text' => $message['text'],
                        'button_id' => $message['button_id'],
                    ], JSON_UNESCAPED_UNICODE),
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

                // Acknowledge unlinked senders to stop retries while ignoring untracked traffic.
                if ($customer === null || $sender === null) {
                    return ['linked' => false];
                }

                $inbound->forceFill(['customer_id' => $customer->id])->save();
                app(Dispatcher::class)->dispatch(new ProcessCustomerWhatsAppMessage($inbound->id, $customer->id, $message, $sender));

                return ['queued' => true];
            });
            $queuedCount += ($receipt['queued'] ?? false) ? 1 : 0;
        }

        return response()->json([
            'ok' => true,
            'queued' => $queuedCount > 0,
            'queued_count' => $queuedCount,
            'received_count' => count($messages),
        ]);
    }

    private function customerForSender(string $sender): ?Customer
    {
        // Customer accounts index Egyptian numbers in local form. An explicit
        // WhatsApp assignment always wins; only use login phones when no active
        // customer has explicitly claimed the sender number.
        $phone = Customer::normalizePhone($sender);
        $explicit = Customer::query()
            ->where('whatsapp_phone', $phone)
            ->limit(2)
            ->get();
        if ($explicit->count() > 0) {
            $customer = $explicit->count() === 1 ? $explicit->first() : null;

            return $customer instanceof Customer && $customer->isWholesaleApproved() ? $customer : null;
        }

        $matches = Customer::query()
            ->whereNull('whatsapp_phone')
            ->where('phone', $phone)
            ->where('type', 'wholesale')
            ->where('status', 'approved')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Normalizes messages from the WhatsApp Cloud API envelope. Flat local test
     * payloads are intentionally rejected so production follows Meta's contract.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}>
     */
    private function messages(array $payload): array
    {
        $messages = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                $value = is_array($change) ? ($change['value'] ?? null) : null;
                if (! is_array($value)) {
                    continue;
                }
                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message) || ! isset($message['id'], $message['from'])) {
                        continue;
                    }
                    $type = (string) ($message['type'] ?? '');
                    $kind = match ($type) {
                        'text', 'interactive' => 'text',
                        'image', 'audio' => $type,
                        default => null,
                    };
                    if ($kind === null) {
                        continue;
                    }
                    $mediaId = match ($kind) {
                        'image' => isset($message['image']['id']) ? (string) $message['image']['id'] : null,
                        'audio' => isset($message['audio']['id']) ? (string) $message['audio']['id'] : null,
                        default => null,
                    };
                    $buttonId = $message['interactive']['button_reply']['id']
                        ?? $message['interactive']['list_reply']['id']
                        ?? null;
                    $text = $message['text']['body'] ?? $message['image']['caption'] ?? '';

                    $messages[] = [
                        'id' => (string) $message['id'],
                        'from' => (string) $message['from'],
                        'text' => is_string($text) ? $text : '',
                        'button_id' => is_string($buttonId) ? $buttonId : null,
                        'kind' => $kind,
                        'media_id' => $mediaId,
                    ];
                }
            }
        }

        return $messages;
    }
}
