<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;

/**
 * Validates discrete integer quantity values within allowable domain boundaries.
 */
final class DiscreteQuantity
{
    /**
     * Assert that the given quantity is a finite integer within allowed business limits.
     *
     * @param  mixed  $qty
     * @param  bool  $allowZero
     * @return void
     *
     * @throws BusinessException
     */
    public static function assert(mixed $qty, bool $allowZero = false): void
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)
            || (float) $qty < ($allowZero ? 0 : 1) || (float) $qty > 99999999
            || abs((float) $qty - round((float) $qty)) > 1e-9) {
            throw new BusinessException('الكمية يجب أن تكون عددًا صحيحًا ضمن الحد المسموح.');
        }
    }
}
