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

    private static function canonicalize(mixed $value, ?string $field = null): mixed
    {
        if (is_string($value) && is_numeric($value)
            && ($field === 'id' || str_ends_with($field ?? '', '_id')
                || in_array($field, ['quantity', 'requested_qty', 'counted_qty', 'approved_qty', 'shipped_qty', 'received_qty'], true))) {
            $value = $value + 0;
        }

        if (is_float($value) && floor($value) === $value && abs($value) <= 9007199254740991) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = [];
        foreach ($value as $key => $item) {
            $mapped[$key] = self::canonicalize($item, is_string($key) ? $key : null);
        }

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}
