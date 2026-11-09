<?php

declare(strict_types=1);

namespace App\Services\Shared;

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
     * @param  array<int, float>  $weights  Proportional weighting for each target bucket.
     * @return array<int, float> Distributed amounts summing exactly to total.
     */
    public static function distribute(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        // Convert to integer cents upfront to eliminate floating-point drift during penny apportionment.
        $totalCents = (int) round($total * 100);
        $weightSum = array_sum($weights);
        if ($totalCents <= 0 || $weightSum <= 0) {
            return array_fill(0, $count, 0.0);
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
