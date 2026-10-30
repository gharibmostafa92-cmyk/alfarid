<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use JsonException;

/**
 * Computes deterministic request payload fingerprints for idempotency evaluation.
 *
 * Sorts array keys deterministically and normalizes scalar numeric representations
 * so cosmetic serialization variations (e.g. "5" vs 5 vs 5.0) yield identical SHA-256 digests.
 */
final class IdempotencyFingerprint
{
    /**
     * Compute SHA-256 fingerprint for a canonicalized payload array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new BusinessException('بيانات الطلب تحتوي قيمة غير صالحة.');
        }
    }

    private static function canonicalize(mixed $value, ?string $field = null): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new BusinessException('يجب أن تكون القيم الرقمية محدودة.');
        }
        // M2: textual decimal normalization without precision loss — strip
        // redundant zeros instead of rounding, so 10.1234 and 10.1299 never
        // share a fingerprint (no silent replay of a corrected price), and no
        // float rounding error is introduced by (float) casts.
        if (is_numeric($value) && in_array($field, [
            'amount', 'subtotal', 'tax_amount', 'unit_price', 'line_total',
            'grand_total', 'discount_amount', 'total', 'total_amount', 'paid_amount',
            'expected_total', 'unit_cost',
        ], true)) {
            return self::normalizeDecimal((string) $value);
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

    /**
     * Normalize a decimal string without rounding: trim sign/zeros, drop a
     * trailing decimal point, keep every significant digit intact.
     */
    private static function normalizeDecimal(string $raw): string
    {
        $raw = trim($raw);
        $sign = '';
        if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
            $sign = $raw[0] === '-' ? '-' : '';
            $raw = substr($raw, 1);
        }

        // Keep the coefficient compact: expanding user-supplied exponents
        // could allocate gigabytes. No float conversion or rounding is needed.
        if (preg_match('/\A(\d*(?:\.\d*)?)(?:[eE]([+-]?\d+))?\z/', $raw, $parts) !== 1) {
            throw new BusinessException('قيمة رقمية غير صالحة.');
        }
        $coefficient = $parts[1];
        $point = strpos($coefficient, '.');
        $fractionLength = $point === false ? 0 : strlen($coefficient) - $point - 1;
        $digits = ltrim(str_replace('.', '', $coefficient), '0');
        if ($digits === '') {
            return '0';
        }
        $significant = rtrim($digits, '0');
        $exponent = bcadd($parts[2] ?? '0', (string) (strlen($digits) - strlen($significant) - $fractionLength), 0);

        return $sign.$significant.'e'.$exponent;
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
        $sortKey = static function (array $item): array {
            foreach (['order_item_id', 'purchase_order_item_id', 'id', 'product_variant_id'] as $rank => $key) {
                if (isset($item[$key])) {
                    return [$rank, $item[$key], json_encode($item, JSON_THROW_ON_ERROR)];
                }
            }

            return [4, '', json_encode($item, JSON_THROW_ON_ERROR)];
        };

        return $sortKey($left) <=> $sortKey($right);
    }
}
