<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappLinkToken;
use App\Services\Ai\WhatsApp\E164Phone;
use App\Services\Shared\WebhookSignatureVerifier;
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
        if ($remoteJid === '') {
            return response()->json(['message' => 'invalid sender'], 422);
        }
        if (! $this->knownSender($remoteJid, $remoteAlt) && $this->unknownLimited($remoteJid, $remoteAlt, $text)) {
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
        $jids = array_values(array_filter([$remoteJid, $remoteAlt]));
        $phones = array_values(array_unique(array_filter(array_map(E164Phone::fromWhatsappJid(...), $jids))));

        return AiWhatsappIdentity::query()
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->where(function ($query) use ($jids, $phones): void {
                foreach ($jids as $key) {
                    $query->orWhere('wa_jid', $key)->orWhere('wa_lid', $key);
                }
                if ($phones !== []) {
                    $query->orWhereIn('phone_e164', $phones);
                }
            })
            ->exists();
    }

    private function unknownLimited(string $remoteJid, ?string $remoteAlt, string $text): bool
    {
        $link = str_starts_with(trim($text), 'ربط');
        $senderKeys = array_values(array_unique(array_filter([$remoteJid, $remoteAlt])));
        sort($senderKeys, SORT_STRING);
        $keys = array_map(fn (string $sender): string => 'wa-unknown:'.hash('sha256', $sender), $senderKeys);
        if ($link && preg_match('/^ربط\s+([A-Za-z0-9]{4}(?:-[A-Za-z0-9]{4}){1,2})\s*$/u', trim($text), $matches)) {
            $tokenHash = hash('sha256', strtoupper($matches[1]));
            $identityId = AiWhatsappLinkToken::query()
                ->where('token_hash', $tokenHash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->value('identity_id');
            if ($identityId !== null) {
                $keys[] = 'wa-unknown-identity:'.$identityId;
            }
        }
        $max = $link ? 8 : 5;
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }
        foreach ($keys as $key) {
            RateLimiter::hit($key, 3600);
        }

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
