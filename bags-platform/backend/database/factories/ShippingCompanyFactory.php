<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingCompany>
 */
class ShippingCompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => 'ship-'.fake()->unique()->numerify('###'),
            'api_credentials' => null,
            'tracking_url_template' => null,
            'is_active' => true,
        ];
    }
}
