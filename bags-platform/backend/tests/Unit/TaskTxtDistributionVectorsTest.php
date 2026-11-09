<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shared\ProportionalDistribution;
use PHPUnit\Framework\TestCase;

class TaskTxtDistributionVectorsTest extends TestCase
{
    public function test_task_vectors_reconcile_to_the_penny_without_negative_shares(): void
    {
        $weights = [33.33, 33.33, 33.34];

        $discount = ProportionalDistribution::distribute(10.00, $weights);
        $this->assertCount(3, $discount);
        $this->assertGreaterThanOrEqual(0.0, min($discount));
        $this->assertSame(1000, array_sum(array_map(
            static fn (float $share): int => (int) round($share * 100),
            $discount
        )));

        $tax = ProportionalDistribution::distribute(0.01, $weights);
        $this->assertCount(3, $tax);
        $this->assertGreaterThanOrEqual(0.0, min($tax));
        $this->assertSame(1, array_sum(array_map(
            static fn (float $share): int => (int) round($share * 100),
            $tax
        )));
    }
}
