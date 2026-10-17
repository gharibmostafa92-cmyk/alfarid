<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->city().' الكبرى',
            'is_active' => true,
        ];
    }
}
