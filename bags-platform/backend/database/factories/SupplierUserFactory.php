<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<SupplierUser>
 */
class SupplierUserFactory extends Factory
{
    protected $model = SupplierUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '01'.fake()->numberBetween(100000000, 999999999),
            'password' => Hash::make('password123'),
            'is_active' => true,
        ];
    }
}
