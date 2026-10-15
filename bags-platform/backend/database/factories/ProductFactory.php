<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'supplier_id' => null,
            'created_by' => null,
            'code' => 'P'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name_ar' => 'منتج '.fake()->unique()->numerify('####'),
            'name_en' => Str::title($name),
            'slug' => Str::slug($name),
            'description_ar' => fake()->paragraph(),
            'description_en' => fake()->paragraph(),
            'material_ar' => fake()->word(),
            'material_en' => fake()->word(),
            'length_cm' => fake()->randomFloat(2, 10, 80),
            'width_cm' => fake()->randomFloat(2, 10, 60),
            'height_cm' => fake()->randomFloat(2, 5, 50),
            'weight_kg' => fake()->randomFloat(3, 0.1, 10),
            'warranty_months' => fake()->randomElement([null, 6, 12, 24]),
            'warranty_details' => null,
            'country_of_origin' => fake()->country(),
            'status' => ProductStatus::Draft,
            'sales_channel' => SalesChannel::Both,
            'is_featured' => false,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
    }
}
