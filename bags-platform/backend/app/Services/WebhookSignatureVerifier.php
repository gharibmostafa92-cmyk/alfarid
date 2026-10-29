<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cryptographic webhook signature validator supporting multiple gateway drivers:
 * - hmac (default): HMAC-SHA256 over raw request body against designated header.
 * - stripe: Stripe-Signature header format (t=...,v1=...) with 300s replay prevention.
 * - paymob: HMAC-SHA512 over official concatenated transaction dictionary fields.
 * - moyasar: Constant-time comparison of secret_token within payload.
 * All signature checks utilize timing-attack safe hash_equals.
 */
class WebhookSignatureVerifier
{
    /**
     * Verify standard HMAC-SHA256 signature against raw request body.
     *
     * @param  string|null  $secret
     * @param  string  $rawBody
     * @param  string|null  $signature
     * @return bool
     */
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
     * Dispatch signature validation to gateway-specific verifier.
     *
     * @param  string  $driver
     * @param  string|null  $secret
     * @param  string  $rawBody
     * @param  array<string, string|null>  $headers  Lowercase header map
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @param  string|null  $signatureHeader
     * @return bool
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
        // Reject events older than 5 minutes to prevent replay attacks
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
        // Official Paymob callback field order for HMAC-SHA512 calculation
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
