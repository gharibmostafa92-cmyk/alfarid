<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $color = fake()->randomElement([
            ['name_ar' => 'أسود', 'name_en' => 'Black', 'hex' => '#000000'],
            ['name_ar' => 'بني', 'name_en' => 'Brown', 'hex' => '#8B4513'],
            ['name_ar' => 'أزرق', 'name_en' => 'Navy', 'hex' => '#1F2A5A'],
            ['name_ar' => 'بيج', 'name_en' => 'Beige', 'hex' => '#D9C7A7'],
            ['name_ar' => 'أحمر', 'name_en' => 'Red', 'hex' => '#B91C1C'],
            ['name_ar' => 'رمادي', 'name_en' => 'Gray', 'hex' => '#6B7280'],
        ]);
        $cost = fake()->randomFloat(2, 50, 2000);

        return [
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.fake()->unique()->numerify('######'),
            'barcode' => $this->ean13(),
            'color_name_ar' => $color['name_ar'],
            'color_name_en' => $color['name_en'],
            'color_hex' => $color['hex'],
            'size' => fake()->randomElement(['S', 'M', 'L', null]),
            'capacity' => null,
            'cost_price' => $cost,
            'retail_price' => round($cost * 1.6, 2),
            'wholesale_price' => round($cost * 1.35, 2),
            'reorder_level' => fake()->numberBetween(0, 10),
            'weight_kg' => null,
            'sort_order' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /** باركود EAN-13 داخلي سليم الرقم التأكيدي (يبدأ بـ 2). */
    private function ean13(): string
    {
        $base = '2'.str_pad((string) fake()->unique()->numberBetween(0, 99999999999), 11, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - ($sum % 10)) % 10);
    }
}
