<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;

/**
 * Validates quantity values within allowable domain boundaries.
 *
 * L10: accepts up to 4 decimal places like the REST layer (decimal:0,4,
 * max 99999999.9999) — an assistant-driven transfer of 2.5 must be
 * receivable with the same precision.
 */
final class DiscreteQuantity
{
    /**
     * Assert that the given quantity is finite, has at most 4 decimals,
     * and is within allowed business limits.
     *
     * @param  mixed  $qty
     * @param  bool  $allowZero
     * @return void
     *
     * @throws BusinessException
     */
    public static function assert(mixed $qty, bool $allowZero = false): void
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)) {
            throw new BusinessException('الكمية يجب أن تكون رقمًا حتى 4 خانات عشرية ضمن الحد المسموح.');
        }
        $value = (float) $qty;
        if ($value < ($allowZero ? 0 : 1) || $value > 99999999.9999
            || abs($value - round($value, 4)) > 1e-9) {
            throw new BusinessException('الكمية يجب أن تكون رقمًا حتى 4 خانات عشرية ضمن الحد المسموح.');
        }
    }
}
