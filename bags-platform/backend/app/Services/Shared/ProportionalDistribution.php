<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Distributes a currency amount proportionally across line weights
 * using the Hare-Niemeyer (Largest Remainder) method.
 *
 * Guarantees zero penny loss or drift by rounding down initial shares
 * to cents and assigning remainder cents to the largest fractional parts.
 */
final class ProportionalDistribution
{
    /**
     * Allocate a monetary sum across arbitrary proportional weights without penny rounding drift.
     *
     * @param  float  $total  Total currency amount to distribute.
     * @param  array<int, float>  $weights  Proportional weighting for each target bucket. Keys are preserved.
     * @return array<int, float> Distributed amounts summing exactly to total, keyed like the input weights.
     *
     * @throws BusinessException If the inputs cannot represent a valid distribution.
     */
    public static function distribute(float $total, array $weights): array
    {
        $roundedTotal = round($total, 2);
        if (! is_finite($total) || $total < 0 || $total > 999999999999.99
            || abs($total - $roundedTotal) > max(1.0, abs($total)) * PHP_FLOAT_EPSILON * 2) {
            throw new BusinessException('الإجمالي يجب أن يكون مبلغًا غير سالب بدقة خانتين عشريتين.');
        }

        $totalCents = (int) round($roundedTotal * 100);
        $weightSum = BigDecimal::zero();
        $decimalWeights = [];
        foreach ($weights as $key => $weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight < 0) {
                throw new BusinessException('الوزن يجب أن يكون رقمًا غير سالب ومحدودًا.');
            }
            $decimalWeights[$key] = BigDecimal::of(json_encode((float) $weight, JSON_THROW_ON_ERROR));
            $weightSum = $weightSum->plus($decimalWeights[$key]);
        }

        if ($totalCents === 0) {
            return array_fill_keys(array_keys($weights), 0.0);
        }
        if ($weightSum->isZero()) {
            throw new BusinessException('توزيع مبلغ موجب يتطلب وزنًا موجبًا.');
        }

        $floorCents = [];
        $remainders = [];
        $left = $totalCents;
        foreach ($decimalWeights as $key => $weight) {
            $numerator = $weight->multipliedBy($totalCents);
            $floor = $numerator->dividedBy($weightSum, 0, RoundingMode::Down)->toInt();
            $floorCents[$key] = $floor;
            $remainders[$key] = $numerator->minus($weightSum->multipliedBy($floor));
            $left -= $floor;
        }

        uasort($remainders, static fn (BigDecimal $first, BigDecimal $second): int => $second->compareTo($first));
        foreach (array_slice(array_keys($remainders), 0, $left) as $key) {
            $floorCents[$key]++;
        }

        return array_map(static fn (int $cents): float => $cents / 100, $floorCents);
    }
}
