<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AiWhatsappIdentity;
use App\Services\WebhookSignatureVerifier;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppInboundController extends Controller
{
    public function handle(Request $request, WebhookSignatureVerifier $signatures): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 262144) {
            return response()->json(['message' => 'payload too large'], 413);
        }
        $valid = $signatures->verifyTimestamped(
            config('services.whatsapp_gateway.secret'),
            $raw,
            $request->header('X-WA-Signature'),
            $request->header('X-WA-Timestamp'),
        );
        if (! $valid) {
            return response()->json(['message' => 'invalid signature'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_string($payload['wa_message_id'] ?? null) || $payload['wa_message_id'] === '') {
            return response()->json(['message' => 'invalid payload'], 422);
        }
        $messageId = substr((string) $payload['wa_message_id'], 0, 128);
        $remoteJid = is_string($payload['remote_jid'] ?? null) ? substr($payload['remote_jid'], 0, 128) : '';
        $remoteAlt = is_string($payload['remote_jid_alt'] ?? null) ? substr($payload['remote_jid_alt'], 0, 128) : null;
        $text = is_string($payload['text'] ?? null) ? mb_substr($payload['text'], 0, 8000) : '';
        if ($remoteJid !== '' && ! $this->knownSender($remoteJid, $remoteAlt) && $this->unknownLimited($remoteJid, $text)) {
            return response()->json(['ok' => true]);
        }
        DB::transaction(function () use ($messageId, $remoteJid, $remoteAlt, $text, $payload): void {
            $now = now();
            $inserted = DB::table('ai_whatsapp_inbound')->insertOrIgnore([
                'wa_message_id' => $messageId,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                return;
            }
            $inboundId = (int) DB::table('ai_whatsapp_inbound')->where('wa_message_id', $messageId)->value('id');
            app(Dispatcher::class)->dispatch(new ProcessWhatsAppMessage($inboundId, [
                'wa_message_id' => $messageId,
                'remote_jid' => $remoteJid,
                'remote_jid_alt' => $remoteAlt,
                'text' => $text,
                'quoted_id' => is_string($payload['quoted_id'] ?? null) ? substr($payload['quoted_id'], 0, 128) : null,
                'media' => $this->media($payload['media'] ?? null),
            ]));
        });

        return response()->json(['ok' => true]);
    }

    private function knownSender(string $remoteJid, ?string $remoteAlt): bool
    {
        return AiWhatsappIdentity::query()
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->where(function ($query) use ($remoteJid, $remoteAlt): void {
                foreach (array_filter([$remoteJid, $remoteAlt]) as $key) {
                    $query->orWhere('wa_jid', $key)->orWhere('wa_lid', $key);
                }
            })
            ->exists();
    }

    private function unknownLimited(string $remoteJid, string $text): bool
    {
        $link = str_starts_with(trim($text), 'ربط');
        $key = 'wa-unknown:'.hash('sha256', $remoteJid);
        $max = $link ? 8 : 5;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }
        RateLimiter::hit($key, 3600);

        return false;
    }

    /** @return array{id:string,type:string,mime:?string}|null */
    private function media(mixed $media): ?array
    {
        if (! is_array($media) || ! is_string($media['id'] ?? null) || ! is_string($media['type'] ?? null)) {
            return null;
        }

        return [
            'id' => substr($media['id'], 0, 128),
            'type' => substr($media['type'], 0, 32),
            'mime' => is_string($media['mime'] ?? null) ? substr($media['mime'], 0, 64) : null,
        ];
    }
}
