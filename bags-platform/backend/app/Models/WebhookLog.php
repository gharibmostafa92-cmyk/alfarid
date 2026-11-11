<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Webhook delivery and verification audit record.
 */
class WebhookLog extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'payload',
        'signature_valid',
        'status',
        'error',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
        ];
    }

    /**
     * Redact authentication credentials and private secrets from webhook payloads before persistence.
     *
     * Prevents credential exposure in database backups or logs if payment payloads embed auth tokens.
     *
     * @param  array<string, mixed>|null  $payload  Raw webhook payload.
     * @return array<string, mixed>|null Sanitized payload safe for storage.
     */
    public static function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return self::scrub($payload);
    }

    /**
     * Keys are compared after lowercasing and stripping separators, so
     * api-key / api_key / apiKey / APIKey all match "apikey".
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'secrettoken', 'secret', 'apikey', 'webhooksecret',
        'password', 'token', 'accesstoken', 'authorization', 'refreshtoken',
        'clientsecret', 'secretkey',
        // Paymob PII + card data are retained for 90 days and copied into backups.
        'billingdata', 'sourcedata', 'pan', 'cardnumber', 'cvv', 'cvv2',
        'expmonth', 'expyear', 'expiry', 'firstname', 'lastname',
        'phonenumber', 'email', 'street', 'building', 'floor', 'apartment',
        'city', 'state', 'country', 'postalcode',
    ];

    /**
     * Recursively scrub sensitive keys from payload structures.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['-', '_', ' '], '', strtolower($key));

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }
}
