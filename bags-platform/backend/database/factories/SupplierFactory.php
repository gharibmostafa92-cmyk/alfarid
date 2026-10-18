<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SUP-'.fake()->unique()->numberBetween(100, 99999),
            'name' => fake()->name(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '01'.fake()->numberBetween(100000000, 999999999),
            'tax_number' => (string) fake()->numberBetween(100000000, 999999999),
            'commercial_register' => (string) fake()->numberBetween(10000, 99999),
            'address' => fake()->address(),
            'payment_terms_days' => 30,
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ];
    }
}
