<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'image_path' => 'banners/'.fake()->uuid().'.jpg',
            'link_type' => BannerLinkType::None,
            'link_value' => null,
            'position' => BannerPosition::MainSlider,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
