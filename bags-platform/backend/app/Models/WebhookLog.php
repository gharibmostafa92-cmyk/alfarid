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

    private const SENSITIVE_KEYS = [
        'secret_token', 'secret-token', 'secret', 'api_key', 'apikey', 'webhook_secret',
        'password', 'token', 'access_token', 'authorization',
        'client_secret', 'client-secret',
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
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
