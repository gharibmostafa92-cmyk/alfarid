<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipping_company_id' => ShippingCompany::factory(),
            'shipping_zone_id' => ShippingZone::factory(),
            'base_cost' => 50,
            'free_shipping_threshold' => null,
            'cod_fee' => 0,
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
        ];
    }
}
