<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'channel' => 'store',
            'customer_id' => null,
            'guest_name' => fake()->name(),
            'guest_phone' => fake()->phoneNumber(),
            'guest_email' => fake()->safeEmail(),
            'status' => OrderStatus::Pending,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
        ];
    }
}
