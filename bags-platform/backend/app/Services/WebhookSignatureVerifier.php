<?php

declare(strict_types=1);

namespace App\Services;

/**
 * التحقق من توقيع الويب هوك: HMAC-SHA256 لنص الطلب الخام بسر المزود،
 * بمقارنة آمنة ضد التوقيت. يقبل بادئة "sha256=" الاختيارية.
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
}
