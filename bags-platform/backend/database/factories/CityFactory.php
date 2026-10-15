<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->unique()->city(),
            'name_en' => fake()->unique()->city(),
            'shipping_cost' => fake()->randomFloat(2, 0, 150),
            'is_active' => true,
        ];
    }
}
