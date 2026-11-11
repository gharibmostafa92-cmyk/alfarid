<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Exceptions\BusinessException;

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
     * @throws BusinessException If any weight is negative.
     */
    public static function distribute(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        // M3: a negative weight (e.g. a discount line) would silently produce
        // negative shares while the sum stays positive — fail loud instead.
        foreach ($weights as $weight) {
            if ((float) $weight < 0) {
                throw new BusinessException('الوزن يجب أن يكون رقمًا غير سالب.');
            }
        }

        // Convert to integer cents upfront to eliminate floating-point drift during penny apportionment.
        $totalCents = (int) round($total * 100);
        $weightSum = array_sum($weights);
        if ($totalCents <= 0 || $weightSum <= 0) {
            // M3: preserve the caller's keys so $shares[$itemId] keeps working
            // on the zero path instead of returning 0..n-1 keys.
            // NOTE: a non-positive total intentionally yields zero shares — pinned
            // by OrderAmountDistributionTest::test_proportional_distribution_handles_edge_cases.
            $zeros = [];
            foreach ($weights as $i => $weight) {
                $zeros[$i] = 0.0;
            }

            return $zeros;
        }

        $floorCents = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $i => $weight) {
            $exact = $totalCents * ((float) $weight / $weightSum);
            $floor = (int) floor($exact);
            $floorCents[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $assigned += $floor;
        }

        $left = $totalCents - $assigned;
        // Allocate remaining surplus cents to positions with the largest truncation remainder.
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $floorCents[$i]++;
            $left--;
        }

        // Restore original index ordering to match input line sequence.
        ksort($floorCents);

        return array_map(static fn (int $cents): float => $cents / 100, $floorCents);
    }
}
