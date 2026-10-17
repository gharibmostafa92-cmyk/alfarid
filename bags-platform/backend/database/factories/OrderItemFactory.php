<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name_snapshot' => fake()->words(2, true),
            'sku_snapshot' => 'SKU-'.fake()->unique()->numerify('#####'),
            'qty' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
        ];
    }

    /** لقطات الاسم والـ SKU من المتغير نفسه بدل بيانات عشوائية. */
    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => [
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $variant->product?->name_ar ?? 'منتج',
            'sku_snapshot' => $variant->sku,
        ]);
    }
}
