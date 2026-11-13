<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymobRefundInquiry
{
    /** Paymob auth tokens expire after roughly an hour; refresh before that. */
    private const TOKEN_TTL_MINUTES = 50;

    public function amount(?string $transactionId, ?string $orderId, array $config): ?float
    {
        $key = $config['api_key'] ?? null;
        if (! is_string($key) || $key === '' || $orderId === null
            || $transactionId === null || preg_match('/\A[0-9]{1,20}\z/', $transactionId) !== 1) {
            return null;
        }

        $currency = strtoupper((string) ($config['currency'] ?? 'EGP'));
        $host = match ($currency) {
            'EGP' => 'https://accept.paymob.com',
            'SAR' => 'https://ksa.paymob.com',
            'AED' => 'https://uae.paymob.com',
            'OMR' => 'https://oman.paymob.com',
            default => null,
        };
        if ($host === null) {
            return null;
        }

        $client = Http::acceptJson()->withoutRedirecting()->connectTimeout(1.5)->timeout(3);
        // M2: one auth token request per cache TTL, not one per refund
        // webhook; auth and inquiry share the client's short timeouts. The TTL
        // stays below Paymob's ~1h token lifetime.
        $tokenCacheKey = 'paymob:refund-auth:'.sha1($host.'|'.$key);
        $token = $this->token($client, $host, $key, $tokenCacheKey);
        if (! is_string($token) || $token === '') {
            return null;
        }

        $response = $this->inquiry($client, $host, $token, $transactionId);
        if ($response === null) {
            return null;
        }
        if (in_array($response->status(), [401, 403], true)) {
            // M2: the cached token aged out early (or was revoked). Refresh once
            // and retry in the same call instead of failing the webhook.
            $token = $this->token($client, $host, $key, $tokenCacheKey, $token);
            if (! is_string($token) || $token === '') {
                return null;
            }
            $response = $this->inquiry($client, $host, $token, $transactionId);
            if ($response === null || in_array($response->status(), [401, 403], true)) {
                return null;
            }
        }

        $transaction = $response->successful() ? $response->json() : null;
        if (! is_array($transaction)
            || (string) ($transaction['id'] ?? '') !== $transactionId
            || (string) data_get($transaction, 'order.id') !== $orderId
            || strtoupper((string) ($transaction['currency'] ?? '')) !== $currency
            || ($transaction['success'] ?? false) !== true
            || ($transaction['pending'] ?? true) !== false) {
            return null;
        }

        $amount = ($transaction['is_voided'] ?? false) === true
            ? ($transaction['amount_cents'] ?? null)
            : ($transaction['refunded_amount_cents_int'] ?? $transaction['refunded_amount_cents'] ?? null);
        if (! is_numeric($amount) || ! is_finite((float) $amount)
            || (float) $amount <= 0 || floor((float) $amount) !== (float) $amount) {
            return null;
        }

        return (float) $amount / 100;
    }

    private function token(PendingRequest $client, string $host, string $key, string $cacheKey, ?string $rejected = null): ?string
    {
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '' && $cached !== $rejected) {
            return $cached;
        }

        $token = Cache::lock($cacheKey.':lock', 10)->get(function () use ($client, $host, $key, $cacheKey, $rejected): ?string {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '' && $cached !== $rejected) {
                return $cached;
            }
            $token = $this->fetchToken($client, $host, $key);
            if ($token !== null) {
                Cache::put($cacheKey, $token, now()->addMinutes(self::TOKEN_TTL_MINUTES));
            }

            return $token;
        });

        return is_string($token) ? $token : null;
    }

    private function fetchToken(PendingRequest $client, string $host, string $key): ?string
    {
        try {
            $auth = $client->post($host.'/api/auth/tokens', ['api_key' => $key]);
        } catch (ConnectionException) {
            Log::warning('Paymob refund inquiry connection failed.');

            return null;
        }

        $token = $auth->successful() ? $auth->json('token') : null;
        if (! is_string($token) || $token === '') {
            Log::warning('Paymob refund inquiry authentication failed.', ['status' => $auth->status()]);

            return null;
        }

        return $token;
    }

    private function inquiry(
        PendingRequest $client,
        string $host,
        string $token,
        string $transactionId,
    ): ?Response {
        try {
            return $client->withToken($token)->get($host.'/api/acceptance/transactions/'.$transactionId);
        } catch (ConnectionException) {
            Log::warning('Paymob refund inquiry connection failed.');

            return null;
        }
    }
}
