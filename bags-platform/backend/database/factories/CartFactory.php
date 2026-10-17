<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_token' => fake()->uuid(),
            'customer_id' => null,
            'coupon_id' => null,
        ];
    }
}
