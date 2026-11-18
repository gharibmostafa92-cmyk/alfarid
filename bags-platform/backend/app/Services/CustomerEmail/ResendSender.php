<?php

declare(strict_types=1);

namespace App\Services\CustomerEmail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Symfony\Component\Mime\Address;
use Throwable;

final class ResendSender
{
    private const API_ENDPOINT = 'https://api.resend.com/emails';

    private const MAX_SAFE_RETRY_AFTER = 86400;

    public function send(string $eventKey, string $jsonBody): ResendResult
    {
        if (! $this->validEventKey($eventKey)) {
            return new ResendResult('permanent', safeCode: 'invalid_event_key');
        }

        $payload = $this->decodeAndValidatePayload($jsonBody);

        if ($payload === null) {
            return new ResendResult('permanent', safeCode: 'invalid_message_payload');
        }

        if (! is_string($payload['from']) || ! $this->validFrom($payload['from'])) {
            return new ResendResult('pause', safeCode: 'resend_sender_configuration');
        }

        $apiKey = config('services.resend.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return new ResendResult('pause', safeCode: 'resend_not_configured');
        }

        if (! config('services.resend.delivery_enabled', false)) {
            return new ResendResult('pause', safeCode: 'delivery_disabled');
        }

        try {
            $response = $this->request($apiKey, $eventKey, $jsonBody)->post(self::API_ENDPOINT);
        } catch (ConnectionException) {
            return new ResendResult('retry', safeCode: 'network_error');
        }

        return $this->classify($response);
    }

    private function request(string $apiKey, string $eventKey, string $jsonBody): PendingRequest
    {
        return Http::withToken($apiKey)
            ->withHeaders(['Idempotency-Key' => $eventKey])
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(2)
            ->timeout(8)
            ->withBody($jsonBody, 'application/json');
    }

    private function classify(Response $response): ResendResult
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $providerId = $this->providerId($response);

            return $providerId === null
                ? new ResendResult('review', safeCode: 'provider_id_missing')
                : new ResendResult('accepted', providerId: $providerId);
        }

        $providerError = $this->providerErrorCode($response);

        if ($status === 408 || $status >= 500) {
            return new ResendResult('retry', safeCode: $status === 408 ? 'upstream_timeout' : 'upstream_unavailable');
        }

        if ($status === 409) {
            return match ($providerError) {
                'concurrent_idempotent_requests' => new ResendResult('retry', safeCode: 'idempotency_in_progress'),
                'invalid_idempotent_request' => new ResendResult('review', safeCode: 'idempotency_payload_mismatch'),
                default => new ResendResult('review', safeCode: 'provider_conflict'),
            };
        }

        if ($status === 429) {
            if ($providerError === 'daily_quota_exceeded') {
                return new ResendResult('pause', safeCode: 'quota_daily_exceeded');
            }

            if ($providerError === 'monthly_quota_exceeded') {
                return new ResendResult('pause', safeCode: 'quota_monthly_exceeded');
            }

            $retryAfter = $this->retryAfterSeconds($response);

            if ($retryAfter === false) {
                return new ResendResult('review', safeCode: 'invalid_retry_after');
            }

            return new ResendResult('retry', retryAfterSeconds: $retryAfter, safeCode: 'rate_limited');
        }

        if ($status === 401) {
            return new ResendResult('pause', safeCode: 'resend_credentials_rejected');
        }

        if ($status === 403) {
            return new ResendResult('pause', safeCode: 'resend_sender_configuration');
        }

        if ($status === 400 || $status === 422) {
            return new ResendResult('permanent', safeCode: 'provider_rejected_message');
        }

        if ($status >= 400 && $status < 500) {
            return new ResendResult('permanent', safeCode: 'provider_rejected_message');
        }

        return new ResendResult('review', safeCode: 'unexpected_provider_response');
    }

    private function providerId(Response $response): ?string
    {
        $json = $this->responseJson($response);
        $id = is_array($json) ? ($json['id'] ?? null) : null;

        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) === 1
            ? $id
            : null;
    }

    private function providerErrorCode(Response $response): ?string
    {
        $json = $this->responseJson($response);

        if (! is_array($json)) {
            return null;
        }

        foreach (['name', 'error', 'code'] as $key) {
            $value = $json[$key] ?? null;

            if (is_string($value) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function responseJson(Response $response): ?array
    {
        $body = $response->body();

        if (strlen($body) > 4096) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function retryAfterSeconds(Response $response): int|false|null
    {
        $value = $response->header('Retry-After');

        if ($value === '') {
            return null;
        }

        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $value) !== 1) {
            return false;
        }

        $seconds = (int) $value;

        return $seconds <= self::MAX_SAFE_RETRY_AFTER ? $seconds : false;
    }

    private function validEventKey(string $eventKey): bool
    {
        return strlen($eventKey) <= 256
            && str_starts_with($eventKey, 'customer-email:')
            && preg_match('/\Acustomer-email:[A-Za-z0-9:._\/-]+\z/D', $eventKey) === 1;
    }

    /** @return array{from: string, to: list<string>, subject: string, text: string}|null */
    private function decodeAndValidatePayload(string $jsonBody): ?array
    {
        if ($jsonBody === '' || strlen($jsonBody) > 16384) {
            return null;
        }

        try {
            $payload = json_decode($jsonBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || array_is_list($payload) || count($payload) !== 4) {
            return null;
        }

        $keys = array_keys($payload);

        if (array_diff($keys, ['from', 'to', 'subject', 'text']) !== []) {
            return null;
        }

        $from = $payload['from'] ?? null;
        $to = $payload['to'] ?? null;
        $subject = $payload['subject'] ?? null;
        $text = $payload['text'] ?? null;

        if (! is_string($from)) {
            return null;
        }

        if (! is_array($to) || ! array_is_list($to) || count($to) !== 1 || ! is_string($to[0] ?? null) || ! $this->validAddress($to[0])) {
            return null;
        }

        if (! is_string($subject) || $subject === '' || mb_strlen($subject) > 200 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            return null;
        }

        if (! is_string($text) || $text === '' || strlen($text) > 12000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return null;
        }

        return $payload;
    }

    private function validFrom(string $from): bool
    {
        if ($from === '' || strlen($from) > 320 || preg_match('/[\x00-\x1F\x7F,;]/', $from) === 1) {
            return false;
        }

        if (str_contains($from, '<') || str_contains($from, '>')) {
            if (preg_match('/\A[^<>]*<[^<>]+>\z/u', $from) !== 1) {
                return false;
            }
        } elseif (preg_match('/\A[^\s<>]+\z/D', $from) !== 1) {
            return false;
        }

        try {
            $address = Address::create($from);
        } catch (Throwable) {
            return false;
        }

        return filter_var($address->getAddress(), FILTER_VALIDATE_EMAIL) !== false
            && substr_count($from, '<') === substr_count($from, '>')
            && substr_count($from, '<') <= 1;
    }

    private function validAddress(string $address): bool
    {
        return strlen($address) <= 255
            && preg_match('/[\x00-\x20\x7F,;<>]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
