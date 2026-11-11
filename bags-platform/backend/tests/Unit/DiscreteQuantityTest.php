<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Shared\DiscreteQuantity;
use PHPUnit\Framework\TestCase;

/** Assistant quantities accept REST-grade 4-decimal precision. */
class DiscreteQuantityTest extends TestCase
{
    public function test_accepts_integers_and_four_decimal_fractions(): void
    {
        $this->assertTrue(DiscreteQuantity::isValid(1));
        $this->assertTrue(DiscreteQuantity::isValid('2.5'));
        $this->assertTrue(DiscreteQuantity::isValid(99999999.9999));
        $this->assertTrue(DiscreteQuantity::isValid(0, allowZero: true));
        $this->assertFalse(DiscreteQuantity::isValid(0, allowZero: false));

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

    public function test_rejects_values_that_decimal_storage_would_silently_round(): void
    {
        // M1: decimal(12,4) stores 0.0000 for 1e-10 and 1.0000 for
        // 1.00000000001; an epsilon float check would accept both past a
        // "greater than zero" rule and risk divide-by-zero cost math.
        foreach (['0.0000000001', 1e-10, '1.00000000001', 0.0000000001] as $bad) {
            $this->assertFalse(DiscreteQuantity::isValid($bad), 'must reject '.var_export($bad, true));
            try {
                DiscreteQuantity::assert($bad);
                $this->fail('Expected rejection for '.var_export($bad, true));
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_accepts_fractional_quantities_between_zero_and_one(): void
    {
        $this->assertTrue(DiscreteQuantity::isValid(0.5));
        $this->assertTrue(DiscreteQuantity::isValid('0.25'));
        $this->assertTrue(DiscreteQuantity::isValid(0.0001));
        $this->assertTrue(DiscreteQuantity::isValid(0.0001, allowZero: true));

        DiscreteQuantity::assert(0.5);
        DiscreteQuantity::assert('0.25');
        $this->assertTrue(true);
    }

    public function test_assert_whole_rejects_values_above_max_decimal(): void
    {
        foreach ([100000000, 1e15, '1e20'] as $bad) {
            try {
                DiscreteQuantity::assertWhole($bad);
                $this->fail('Expected rejection for '.var_export($bad, true));
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }
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
