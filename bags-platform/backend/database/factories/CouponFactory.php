<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??????')),
            'type' => 'percentage',
            'value' => 10,
            'min_order_amount' => null,
            'max_discount_amount' => null,
            'usage_limit' => null,
            'used_count' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
