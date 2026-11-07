<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InventoryService;
use PHPUnit\Framework\TestCase;

class WeightedAverageCostTest extends TestCase
{
    public function test_standard_weighted_average_calculation(): void
    {
        // 10 units @ 50.0000 + 5 units @ 80.0000 = (500 + 400) / 15 = 60.0000
        $avg = InventoryService::calculateWeightedAverageCost('10.0000', '50.0000', 5, '80.0000');
        $this->assertSame('60.0000', $avg);
    }

    public function test_zero_initial_stock_adopts_incoming_unit_cost(): void
    {
        // 0 units @ 0.0000 + 10 units @ 45.2500 = 45.2500
        $avg = InventoryService::calculateWeightedAverageCost('0.0000', '0.0000', 10, '45.2500');
        $this->assertSame('45.2500', $avg);
    }

    public function test_negative_stock_treated_as_zero_floor(): void
    {
        // -3 units (clamped to 0) + 10 units @ 25.5000 = 25.5000
        $avg = InventoryService::calculateWeightedAverageCost('-3.0000', '15.0000', 10, '25.5000');
        $this->assertSame('25.5000', $avg);
    }

    public function test_fractional_precision_and_rounding(): void
    {
        // 3 units @ 10.0000 + 1 unit @ 20.0000 = 50 / 4 = 12.5000
        $avg = InventoryService::calculateWeightedAverageCost('3.0000', '10.0000', 1, '20.0000');
        $this->assertSame('12.5000', $avg);
    }
}
