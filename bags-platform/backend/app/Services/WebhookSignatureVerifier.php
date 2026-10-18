<?php

declare(strict_types=1);

namespace App\Services;

/**
 * التحقق من توقيعات الويب هوك بأنماط البوابات الحقيقية (F-06):
 * - hmac (الافتراضي): HMAC-SHA256 للنص الخام في هيدر واحد.
 * - stripe: هيدر Stripe-Signature بصيغة t=…,v1=… والتوقيع على "{t}.{body}"
 *   مع فحص حداثة الطابع الزمني (≤ 300 ثانية) ضد إعادة الإرسال.
 * - paymob: معامل hmac في الـ query — HMAC-SHA512 لحقول المعاملة
 *   بترتيب Paymob الرسمي المنشور.
 * - moyasar: secret_token داخل جسم الطلب يطابق السر المضبوط.
 * كل المقارنات hash_equals. لا مكتبات خارجية.
 */
class WebhookSignatureVerifier
{
    public function verify(?string $secret, string $rawBody, ?string $signature): bool
    {
        if ($secret === null || $secret === '' || $signature === null || $signature === '') {
            return false;
        }

        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * @param  array<string, string|null>  $headers  مفاتيح بحروف صغيرة
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     */
    public function verifyGateway(string $driver, ?string $secret, string $rawBody, array $headers, array $query, array $payload, ?string $signatureHeader = null): bool
    {
        return match ($driver) {
            'stripe' => $this->verifyStripe($secret, $rawBody, $headers),
            'paymob' => $this->verifyPaymob($secret, $query, $payload),
            'moyasar' => $this->verifyMoyasar($secret, $payload),
            default => $this->verify(
                $secret,
                $rawBody,
                $headers[strtolower($signatureHeader ?? 'X-Webhook-Signature')] ?? null,
            ),
        };
    }

    /** @param array<string, string|null> $headers */
    private function verifyStripe(?string $secret, string $rawBody, array $headers): bool
    {
        $header = $headers['stripe-signature'] ?? null;
        if ($secret === null || $secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || $signatures === [] || ! ctype_digit($timestamp)) {
            return false;
        }
        // حماية من إعادة الإرسال: طابع أقدم من 5 دقائق يُرفض
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower(trim($signature)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     */
    private function verifyPaymob(?string $secret, array $query, array $payload): bool
    {
        $hmac = $query['hmac'] ?? null;
        if ($secret === null || $secret === '' || ! is_string($hmac) || $hmac === '') {
            return false;
        }

        $tx = isset($payload['obj']) && is_array($payload['obj']) ? $payload['obj'] : $payload;
        // ترتيب حقول Paymob الرسمي لرسالة الـ callback
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
        $concatenated = '';
        foreach ($fields as $field) {
            $value = data_get($tx, $field);
            if (is_bool($value)) {
                $concatenated .= $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $concatenated .= (string) $value;
            }
        }

        $expected = hash_hmac('sha512', $concatenated, $secret);

        return hash_equals($expected, strtolower(trim($hmac)));
    }

    /** @param array<string, mixed> $payload */
    private function verifyMoyasar(?string $secret, array $payload): bool
    {
        $token = $payload['secret_token'] ?? null;
        if ($secret === null || $secret === '' || ! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }
}
