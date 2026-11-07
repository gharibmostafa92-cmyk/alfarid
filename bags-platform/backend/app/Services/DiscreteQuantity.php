<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;

/**
 * Validates inventory and transaction quantities against domain boundary limits.
 *
 * Supports continuous units up to 4 decimal places matching database precision (decimal:12,4),
 * as well as strictly indivisible whole discrete units.
 */
final class DiscreteQuantity
{
    /** Maximum allowed decimal quantity matching database decimal(12,4). */
    public const MAX_DECIMAL = 99999999.9999;

    /**
     * Checks whether a quantity is finite, has at most 4 decimal places, and stays within limits.
     *
     * @param  mixed  $qty  Input quantity or cost value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     */
    public static function isValid(mixed $qty, bool $allowZero = false): bool
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)) {
            return false;
        }
        $value = (float) $qty;
        // Epsilon comparison guards against IEEE-754 floating point inaccuracies during decimal checks.
        if ($value < ($allowZero ? 0 : 1) || $value > self::MAX_DECIMAL || abs($value - round($value, 4)) > 1e-9) {
            return false;
        }

        return true;
    }

    /**
     * Assert that a quantity is finite, has at most 4 decimal places, and stays within bounds.
     *
     * @param  mixed  $qty  Input quantity value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     *
     * @throws BusinessException If quantity is non-numeric, out of bounds, or exceeds 4 decimals.
     */
    public static function assert(mixed $qty, bool $allowZero = false): void
    {
        if (! self::isValid($qty, $allowZero)) {
            throw new BusinessException('الكمية يجب أن تكون رقمًا حتى 4 خانات عشرية ضمن الحد المسموح.');
        }
    }

    /**
     * Assert that a quantity is an indivisible, positive whole integer.
     *
     * @param  mixed  $qty  Input quantity value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     * @param  string|null  $customMessage  Optional exception message override.
     *
     * @throws BusinessException If value is fractional, negative, or not a finite number.
     */
    public static function assertWhole(mixed $qty, bool $allowZero = false, ?string $customMessage = null): void
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)) {
            throw new BusinessException($customMessage ?? 'الكمية يجب أن تكون عددًا صحيحًا من القطع.');
        }
        $value = (float) $qty;
        // Discrete piece counts cannot be subdivided into fractional merchandise units.
        if (floor($value) !== $value || $value < ($allowZero ? 0 : 1)) {
            throw new BusinessException($customMessage ?? 'الكمية يجب أن تكون عددًا صحيحًا من القطع.');
        }
    }
}
