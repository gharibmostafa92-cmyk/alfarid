<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
     * تنقية الحمولة قبل التخزين (H1): بوابات مثل Moyasar تُتحقق من
     * secret_token داخل الجسم نفسه، وتخزين الجسم كاملًا يضع السر في قاعدة
     * البيانات فيكفي تسرّب نسخة احتياطية/قراءة لانتحال الأحداث. تُستبدل
     * المفاتيح الحسّاسة بقيمة ثابتة في كل الأعماق قبل الكتابة.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return self::scrub($payload);
    }

    private const SENSITIVE_KEYS = [
        'secret_token', 'secret', 'api_key', 'apikey', 'webhook_secret',
        'password', 'token', 'access_token', 'authorization',
    ];

    /**
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
