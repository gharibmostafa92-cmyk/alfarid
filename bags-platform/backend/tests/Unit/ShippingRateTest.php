<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ShippingRate;
use PHPUnit\Framework\TestCase;

class ShippingRateTest extends TestCase
{
    public function test_qualifies_for_free_shipping(): void
    {
        $rate = new ShippingRate([
            'base_cost' => 50.00,
            'free_shipping_threshold' => 300.00,
            'cod_fee' => 15.00,
        ]);

        $this->assertFalse($rate->qualifiesForFreeShipping(100.00));
        $this->assertFalse($rate->qualifiesForFreeShipping(299.99));
        $this->assertTrue($rate->qualifiesForFreeShipping(300.00));
        $this->assertTrue($rate->qualifiesForFreeShipping(500.00));
    }

    public function test_null_threshold_never_qualifies_for_free_shipping(): void
    {
        $rate = new ShippingRate([
            'base_cost' => 60.00,
            'free_shipping_threshold' => null,
            'cod_fee' => 0.00,
        ]);

        $this->assertFalse($rate->qualifiesForFreeShipping(0.00));
        $this->assertFalse($rate->qualifiesForFreeShipping(10000.00));
    }

    public function test_effective_cost_calculation(): void
    {
        $rateWithThreshold = new ShippingRate([
            'base_cost' => 45.00,
            'free_shipping_threshold' => 200.00,
        ]);

        $this->assertSame(45.00, $rateWithThreshold->effectiveCost(150.00));
        $this->assertSame(0.00, $rateWithThreshold->effectiveCost(200.00));
        $this->assertSame(0.00, $rateWithThreshold->effectiveCost(250.00));

        $rateWithoutThreshold = new ShippingRate([
            'base_cost' => 40.00,
            'free_shipping_threshold' => null,
        ]);

        $this->assertSame(40.00, $rateWithoutThreshold->effectiveCost(1000.00));
    }
}
