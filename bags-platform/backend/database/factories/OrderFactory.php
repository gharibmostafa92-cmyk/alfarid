<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Order $order): void {
            $subtotal = round((float) $order->subtotal, 2);
            $discount = round((float) $order->discount_amount, 2);
            $shipping = round((float) $order->shipping_cost, 2);
            $tax = round((float) $order->tax_amount, 2);
            $cod = round((float) $order->cod_fee, 2);
            $grand = round((float) $order->grand_total, 2);
            $formula = round(($subtotal - $discount) + $shipping + $tax + $cod, 2);
            if (abs($formula - $grand) > 0.001) {
                $order->subtotal = round($grand + $discount - $shipping - $tax - $cod, 2);
            }
        });
    }

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
