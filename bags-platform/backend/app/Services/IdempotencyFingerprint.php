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
        // L2: تكلفة الوحدة بدقة الحقل (4 خانات) لا خانتين — طلبان بنفس
        // المفتاح يختلفان في الثالثة/الرابعة حمولتان مختلفتان (409) لا إعادة.
        if (is_numeric($value) && $field === 'unit_cost') {
            return (int) round(((float) $value) * 10000);
        }

        if (is_numeric($value) && in_array($field, [
            'amount', 'subtotal', 'tax_amount', 'unit_price', 'line_total',
            'grand_total', 'discount_amount', 'total', 'total_amount', 'paid_amount',
            'expected_total',
        ], true)) {
            return (int) round(((float) $value) * 100);
        }

        if (is_string($value) && is_numeric($value)
            && ($field === 'id' || str_ends_with($field ?? '', '_id')
                || in_array($field, ['qty', 'amount', 'received_good_qty', 'received_damaged_qty', 'quantity', 'requested_qty', 'counted_qty', 'approved_qty', 'shipped_qty', 'received_qty'], true))) {
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

        if ($isList && $mapped !== [] && self::isItemList($mapped)) {
            usort($mapped, self::itemSort(...));
        }

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }

    /** @param list<mixed> $items */
    private static function isItemList(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || (! isset($item['id']) && ! isset($item['order_item_id']) && ! isset($item['purchase_order_item_id']) && ! isset($item['product_variant_id']))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $left */
    private static function itemSort(array $left, array $right): int
    {
        foreach (['order_item_id', 'purchase_order_item_id', 'id', 'product_variant_id'] as $key) {
            if (isset($left[$key], $right[$key])) {
                return $left[$key] <=> $right[$key];
            }
        }

        return 0;
    }
}
