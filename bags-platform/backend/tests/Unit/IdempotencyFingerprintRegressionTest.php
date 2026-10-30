<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\IdempotencyFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdempotencyFingerprintRegressionTest extends TestCase
{
    public function test_generated_payloads_preserve_fingerprints_across_numeric_and_order_variations(): void
    {
        for ($sample = 1; $sample <= 1000; $sample++) {
            $amount = $sample % 97 + 1;
            $items = [
                ['id' => 1, 'qty' => $sample % 7 + 1, 'unit_cost' => $amount],
                ['id' => '1', 'qty' => 2, 'unit_cost' => $amount + 1],
                ['product_variant_id' => $sample + 2, 'qty' => 3, 'unit_cost' => $amount + 2],
            ];
            $equivalent = array_map(static fn ($item) => array_reverse(array_map(
                static fn ($value) => is_numeric($value) ? (string) $value.'.000' : $value, $item,
            ), true), array_reverse($items));
            $this->assertSame(
                IdempotencyFingerprint::hash(['amount' => $amount, 'items' => $items, 'notes' => 'sample-'.$sample]),
                IdempotencyFingerprint::hash(['notes' => 'sample-'.$sample, 'items' => $equivalent, 'amount' => '00'.$amount.'.000']),
                'Equivalent generated payload '.$sample,
            );
        }
    }

    public function test_invalid_numeric_and_utf8_values_are_business_validation_errors(): void
    {
        foreach ([INF, NAN, "\xFF"] as $value) {
            try {
                IdempotencyFingerprint::hash(['amount' => $value]);
                $this->fail('Invalid data must be rejected.');
            } catch (BusinessException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function test_heterogeneous_item_order_is_deterministic(): void
    {
        $items = [['order_item_id' => 9], ['product_variant_id' => 2], ['id' => 7], ['order_item_id' => 1]];
        $expected = IdempotencyFingerprint::hash(['items' => $items]);
        for ($offset = 1; $offset < count($items); $offset++) {
            $this->assertSame($expected, IdempotencyFingerprint::hash(['items' => [...array_slice($items, $offset), ...array_slice($items, 0, $offset)]]));
        }
    }

    #[DataProvider('equivalentDecimals')]
    public function test_equivalent_decimal_spellings_have_the_same_fingerprint(string $left, string $right): void
    {
        $this->assertSame(
            IdempotencyFingerprint::hash(['amount' => $left]),
            IdempotencyFingerprint::hash(['amount' => $right]),
        );
    }

    public static function equivalentDecimals(): array
    {
        return [
            ['10.123400', '010.1234'],
            ['0.5e1', '5'],
            ['001e0', '1'],
            ['0.0015E+2', '0.15'],
            ['-0.05e-1', '-0.005'],
            ['0e10', '0'],
        ];
    }

    public function test_distinct_high_precision_prices_do_not_share_a_fingerprint(): void
    {
        $this->assertNotSame(
            IdempotencyFingerprint::hash(['unit_price' => '10.1234']),
            IdempotencyFingerprint::hash(['unit_price' => '10.1299']),
        );
    }

    public function test_scientific_notation_does_not_collide_with_a_different_amount(): void
    {
        $this->assertNotSame(
            IdempotencyFingerprint::hash(['amount' => '0.5e1']),
            IdempotencyFingerprint::hash(['amount' => '50']),
        );
    }

    public function test_large_exponents_remain_compact_and_keep_numeric_equivalence(): void
    {
        $this->assertSame(
            IdempotencyFingerprint::hash(['amount' => '1e999999999']),
            IdempotencyFingerprint::hash(['amount' => '10e999999998']),
        );
        $this->assertNotSame(
            IdempotencyFingerprint::hash(['amount' => '1e999999999']),
            IdempotencyFingerprint::hash(['amount' => '1e999999998']),
        );
    }
}
