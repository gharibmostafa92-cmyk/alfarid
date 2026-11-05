<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Catalog\Tools\BulkUpdatePricesTool;
use PHPUnit\Framework\TestCase;

/**
 * task.txt item 5: charm-price rounding can invert the operation direction.
 * These tests pin the CURRENT behavior. The end_with_99 inversion
 * (a discount producing a HIGHER price) is reported as a bug awaiting an
 * owner decision: reject such proposals, or keep the rounding.
 */
class P07RoundingTest extends TestCase
{
    public function test_documented_rounding_cases(): void
    {
        // (أ) 110 −10% → 99, then end_with_9 keeps 99.
        $this->assertSame(99.0, BulkUpdatePricesTool::applyRounding(110 * 0.9, 'end_with_9'));
        // (ج) 110 +10% → 121, then end_with_9 lifts to 129.
        $this->assertSame(129.0, BulkUpdatePricesTool::applyRounding(110 * 1.1, 'end_with_9'));
        // nearest modes behave symmetrically.
        $this->assertSame(100.0, BulkUpdatePricesTool::applyRounding(99, 'nearest_5'));
        $this->assertSame(100.0, BulkUpdatePricesTool::applyRounding(99, 'nearest_10'));
        $this->assertSame(99.99, BulkUpdatePricesTool::applyRounding(99.994, 'none'));
    }

    public function test_end_with_99_can_invert_a_discount(): void
    {
        // (ب) 100 −10% → 90 raw, but end_with_99 maps 90 back up to 99:
        // the rounding moves against the discount direction.
        $raw = 100 * 0.9;
        $this->assertSame(90.0, $raw);
        $rounded = BulkUpdatePricesTool::applyRounding($raw, 'end_with_99');
        $this->assertSame(99.0, $rounded);
        $this->assertGreaterThan($raw, $rounded, 'rounding moved against the discount direction');

        // (د) increases can invert too: +10% on 100 → raw 110 → end_with_99 99,
        // violating the "increase ⇒ new >= old" property the plan requires.
        // Pinned here as CURRENT behavior; reported as a bug for an owner decision.
        $this->assertSame(99.0, BulkUpdatePricesTool::applyRounding(100 * 1.1, 'end_with_99'));

        // end_with_9 preserves increase direction on the same input.
        $this->assertSame(119.0, BulkUpdatePricesTool::applyRounding(100 * 1.1, 'end_with_9'));
    }
}
