<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Shared\ProportionalDistribution;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class OrderAmountDistributionTest extends TestCase
{
    public function test_generated_shares_are_nonnegative_and_preserve_every_cent(): void
    {
        $random = new Randomizer(new Mt19937(20261106));

        for ($sample = 0; $sample < 1000; $sample++) {
            $totalCents = $sample % 10 === 0 ? 0 : $random->getInt(1, 1_000_000);
            $weights = [$random->getInt(1, 50_000) / 100];
            $lineCount = $random->getInt(1, 50);
            for ($line = 1; $line < $lineCount; $line++) {
                $weights[] = $random->getInt(0, 50_000) / 100;
            }

            $shares = ProportionalDistribution::distribute($totalCents / 100, $weights);
            $this->assertCount($lineCount, $shares);
            $this->assertGreaterThanOrEqual(0.0, min($shares));
            $this->assertSame($totalCents, array_sum(array_map(
                static fn (float $share): int => (int) round($share * 100),
                $shares,
            )), "Sample {$sample}");
        }
    }

    public function test_zero_path_preserves_original_keys(): void
    {
        $this->assertSame(
            [17 => 0.0, 42 => 0.0],
            ProportionalDistribution::distribute(0.0, [17 => 5.0, 42 => 15.0])
        );
    }

    public function test_negative_weight_is_rejected(): void
    {
        try {
            ProportionalDistribution::distribute(100.0, [50.0, -5.0]);
            $this->fail('negative weight must be rejected');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_distribution_rejects_fractional_cents_and_missing_weights(): void
    {
        foreach ([[1.005, [1]], [100.0, [0, 0]], [100.0, []]] as [$total, $weights]) {
            try {
                ProportionalDistribution::distribute($total, $weights);
                $this->fail('Expected invalid distribution to be rejected.');
            } catch (BusinessException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function test_proportional_distribution_handles_edge_cases(): void
    {
        $this->assertSame([], ProportionalDistribution::distribute(0.0, []));
        $this->assertSame([0.0, 0.0], ProportionalDistribution::distribute(0.0, [10.0, 20.0]));
        $this->assertSame([33.34, 33.33, 33.33], ProportionalDistribution::distribute(100.0, [1.0, 1.0, 1.0]));

        // task2 M1: a negative total is a refund/credit, not a distribution.
        // It must fail loud instead of silently returning zero shares.
        $this->expectException(BusinessException::class);
        ProportionalDistribution::distribute(-50.0, [10.0, 20.0]);
    }
}
