<?php

declare(strict_types=1);

namespace App\Services;

/**
 * بصمة حمولات عدم التكرار: ترتيب حتمي لمفاتيح المصفوفات وتطبيع للأرقام،
 * بحيث تنتج الاختلافات الشكلية في تسلسل العميل ("5" مقابل 5 مقابل 5.0)
 * نفس البصمة، فلا تتحول إعادة إرسال مطابقة إلى 409 زائف (L-2).
 */
final class IdempotencyFingerprint
{
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if (is_float($value) && floor($value) === $value && abs($value) <= 9007199254740991) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn ($item) => self::canonicalize($item), $value);

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}
