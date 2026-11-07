<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\DiscreteQuantity;
use PHPUnit\Framework\TestCase;

/** Assistant quantities accept REST-grade 4-decimal precision. */
class DiscreteQuantityTest extends TestCase
{
    public function test_accepts_integers_and_four_decimal_fractions(): void
    {
        DiscreteQuantity::assert(1);
        DiscreteQuantity::assert('2.5');
        DiscreteQuantity::assert(99999999.9999);
        DiscreteQuantity::assert(0, allowZero: true);
        $this->assertTrue(true);
    }

    public function test_rejects_non_finite_out_of_range_and_over_precise_values(): void
    {
        foreach ([0, -1, 0.00001, 100000000, NAN, INF, 'abc', 1.12345] as $bad) {
            try {
                DiscreteQuantity::assert($bad);
                $this->fail('Expected rejection for '.var_export($bad, true));
            } catch (BusinessException) {
            }
        }
        $this->assertTrue(true);
    }

    public function test_assert_whole_accepts_positive_integers_and_rejects_fractions(): void
    {
        DiscreteQuantity::assertWhole(1);
        DiscreteQuantity::assertWhole('5');
        DiscreteQuantity::assertWhole(100.0);
        DiscreteQuantity::assertWhole(0, allowZero: true);

        foreach ([0, -1, 1.5, '2.3', NAN, INF, 'xyz'] as $bad) {
            try {
                DiscreteQuantity::assertWhole($bad);
                $this->fail('Expected rejection for '.var_export($bad, true));
            } catch (BusinessException $e) {
                $this->assertSame('الكمية يجب أن تكون عددًا صحيحًا من القطع.', $e->getMessage());
            }
        }
    }
}
