<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OrderService;
use App\Services\ProportionalDistribution;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

class OrderAmountDistributionTest extends TestCase
{
    public function test_generated_shares_are_nonnegative_and_preserve_every_cent(): void
    {
        $service = new ReflectionClass(OrderService::class);
        $orders = $service->newInstanceWithoutConstructor();
        $distribute = $service->getMethod('distributeLargestRemainder');
        $random = new Randomizer(new Mt19937(20261106));

        for ($sample = 0; $sample < 1000; $sample++) {
            $totalCents = $sample % 10 === 0 ? 0 : $random->getInt(1, 1_000_000);
            $weights = [$random->getInt(1, 50_000) / 100];
            $lineCount = $random->getInt(1, 50);
            for ($line = 1; $line < $lineCount; $line++) {
                $weights[] = $random->getInt(0, 50_000) / 100;
            }

            $shares = $distribute->invoke($orders, $totalCents / 100, $weights);
            $this->assertCount($lineCount, $shares);
            $this->assertGreaterThanOrEqual(0.0, min($shares));
            $this->assertSame($totalCents, array_sum(array_map(
                static fn (float $share): int => (int) round($share * 100),
                $shares,
            )), "Sample {$sample}");
        }
    }

    public function test_proportional_distribution_handles_edge_cases(): void
    {
        $this->assertSame([], ProportionalDistribution::distribute(100.0, []));
        $this->assertSame([0.0, 0.0], ProportionalDistribution::distribute(0.0, [10.0, 20.0]));
        $this->assertSame([0.0, 0.0], ProportionalDistribution::distribute(-50.0, [10.0, 20.0]));
        $this->assertSame([0.0, 0.0], ProportionalDistribution::distribute(100.0, [0.0, 0.0]));
        $this->assertSame([33.34, 33.33, 33.33], ProportionalDistribution::distribute(100.0, [1.0, 1.0, 1.0]));
    }
}
