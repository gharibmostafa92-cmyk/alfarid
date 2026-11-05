<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class WhatsAppGatewayClient
{
    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->request('GET', '/status')->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function qr(): array
    {
        return $this->request('GET', '/qr')->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function logout(): array
    {
        return $this->request('POST', '/logout', [])->json() ?? [];
    }

    public function send(string $toJid, string $text, ?string $quotedId = null): string
    {
        $payload = ['to_jid' => $toJid, 'text' => $text];
        if ($quotedId !== null && $quotedId !== '') {
            $payload['quoted_id'] = $quotedId;
        }
        $body = $this->request('POST', '/send', $payload)->json() ?? [];
        $id = $body['wa_message_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new WhatsAppGatewayException('بوابة واتساب مرجّعتش رقم الرسالة.');
        }

        return $id;
    }

    public function presence(string $toJid, string $state): void
    {
        try {
            $this->request('POST', '/presence', ['to_jid' => $toJid, 'state' => $state], 2);
        } catch (WhatsAppGatewayException) {
            // Presence is best effort and must never interrupt a turn.
        }
    }

    public function downloadMedia(string $id): string
    {
        return $this->request('POST', '/media/'.rawurlencode($id), [], 30)->body();
    }

    /** @return array{mime:string}|null */
    public function mediaMeta(string $id): ?array
    {
        $body = $this->request('GET', '/media/'.rawurlencode($id).'/meta')->json();
        if (! is_array($body) || ! is_string($body['mime'] ?? null)) {
            return null;
        }

        return ['mime' => $body['mime']];
    }

    public function deleteMessage(string $toJid, string $waMessageId): void
    {
        try {
            $this->request('POST', '/delete', ['to_jid' => $toJid, 'wa_message_id' => $waMessageId]);
        } catch (WhatsAppGatewayException) {
            // حذف رسالة الرقم السري أفضلية، مش شرط لإتمام الفتح.
        }
    }

    /** @return array<string, mixed> */
    public function encodeQr(string $text): array
    {
        return $this->request('POST', '/encode-qr', ['text' => $text])->json() ?? [];
    }

    /** @param array<string, mixed>|null $json */
    private function request(string $method, string $path, ?array $json = null, ?int $timeout = null): Response
    {
        $secret = (string) config('services.whatsapp_gateway.secret');
        $base = rtrim((string) config('services.whatsapp_gateway.url'), '/');
        if ($secret === '' || $base === '') {
            throw new WhatsAppGatewayException('بوابة واتساب مش متظبطة.');
        }
        $body = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        try {
            $response = Http::connectTimeout(3)
                ->timeout($timeout ?? (int) config('services.whatsapp_gateway.timeout_seconds', 10))
                ->withHeaders([
                    'X-WA-Timestamp' => $timestamp,
                    'X-WA-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->send($method, $base.$path);
        } catch (ConnectionException $exception) {
            throw new WhatsAppGatewayException('تعذر الاتصال ببوابة واتساب.', previous: $exception);
        }
        if ($response->failed()) {
            throw new WhatsAppGatewayException('بوابة واتساب ردت بخطأ.', $response->status());
        }

        return $response;
    }
}
