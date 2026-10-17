<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'عرض '.fake()->word(),
            'type' => 'percentage_discount',
            'value' => 15,
            'min_order_amount' => null,
            'applies_to' => 'all',
            'target_ids' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
