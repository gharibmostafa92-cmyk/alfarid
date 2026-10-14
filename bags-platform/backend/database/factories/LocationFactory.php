<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->words(2, true),
            'code' => 'A-'.fake()->unique()->numerify('##-##'),
            'type' => LocationType::Shelf,
            'is_active' => true,
        ];
    }
}
