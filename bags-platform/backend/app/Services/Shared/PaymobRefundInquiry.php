<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymobRefundInquiry
{
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

        $client = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4);
        try {
            $auth = $client->post($host.'/api/auth/tokens', ['api_key' => $key]);
            $token = $auth->successful() ? $auth->json('token') : null;
            if (! is_string($token) || $token === '') {
                Log::warning('Paymob refund inquiry authentication failed.', ['status' => $auth->status()]);

                return null;
            }

            $response = $client->withToken($token)->get($host.'/api/acceptance/transactions/'.$transactionId);
        } catch (ConnectionException) {
            Log::warning('Paymob refund inquiry connection failed.');

            return null;
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
}
