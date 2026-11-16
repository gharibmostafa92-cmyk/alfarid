<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
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
            'email_verified_at' => now(),
            'phone' => fake()->unique()->numerify('010########'),
            'company_name' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->company(),
            'tax_number' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->numerify('#########'),
            'commercial_register' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->numerify('######'),
            'password' => Hash::make('password'),
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            if ($customer->isCommercialTier() && $customer->addresses()->count() === 0) {
                $city = \App\Models\City::query()->first() ?? \App\Models\City::factory()->create();
                $customer->addresses()->create([
                    'city_id' => $city->id,
                    'address_line' => 'مقر التاجر - شارع الأزهر',
                    'is_default' => true,
                ]);
            }
        });
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
