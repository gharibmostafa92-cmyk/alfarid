<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('01#########'),
            'password' => Hash::make('password'),
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ];
    }

    public function wholesale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Pending,
            'company_name' => fake()->company(),
            'tax_number' => fake()->unique()->numerify('#########'),
            'commercial_register' => fake()->unique()->numerify('######'),
        ]);
    }
}
