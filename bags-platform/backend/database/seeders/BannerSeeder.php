<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Models\Banner;
use App\Support\BannerImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class BannerSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('BannerSeeder is dev-only; skipped in production.');

            return;
        }
        $disk = Storage::disk(BannerImage::disk());
        $sources = [
            'sophie-hero-fallback.png' => $this->copy($disk, 'sophie-hero-fallback.png'),
            'sophie-promo-1.jpg' => $this->copy($disk, 'sophie-promo-1.jpg'),
            'sophie-promo-2.jpg' => $this->copy($disk, 'sophie-promo-2.jpg'),
        ];
        $hero = $sources['sophie-hero-fallback.png'];
        $p1 = $sources['sophie-promo-1.jpg'];
        $p2 = $sources['sophie-promo-2.jpg'];

        $rows = [
            ['title' => 'Sophie Bag Collection', 'image_path' => $hero, 'position' => BannerPosition::MainSlider, 'sort_order' => 1,
                'kicker' => 'NEW COLLECTION', 'headline' => 'TIMELESS', 'headline_bold' => 'BAGS',
                'description' => 'Crafted pieces for every day — discover the collection.', 'cta_label' => 'SHOP NOW', 'bg_color' => null],
            ['title' => 'Leather Edit', 'image_path' => $p1, 'position' => BannerPosition::MainSlider, 'sort_order' => 2,
                'kicker' => null, 'headline' => null, 'headline_bold' => 'LEATHER', 'description' => null, 'cta_label' => null, 'bg_color' => null],
            ['title' => 'Seasonal Slider', 'image_path' => $p2, 'position' => BannerPosition::MainSlider, 'sort_order' => 3,
                'kicker' => null, 'headline' => null, 'headline_bold' => null, 'description' => null, 'cta_label' => null, 'bg_color' => null],
            ['title' => 'Backpack Collection', 'image_path' => $p1, 'position' => BannerPosition::MiddleBanner, 'sort_order' => 1,
                'kicker' => 'NEW COLLECTION', 'headline' => null, 'headline_bold' => 'Backpack', 'description' => null, 'cta_label' => 'View collection', 'bg_color' => '#dfe6ec'],
            ['title' => 'Bag Accessories', 'image_path' => $p2, 'position' => BannerPosition::MiddleBanner, 'sort_order' => 2,
                'kicker' => 'BAG ACCESSORIES', 'headline' => null, 'headline_bold' => 'Accessories', 'description' => null, 'cta_label' => 'View collection', 'bg_color' => '#ede3de'],
            ['title' => 'Popup Offer', 'image_path' => $p2, 'position' => BannerPosition::Popup, 'sort_order' => 1,
                'kicker' => null, 'headline' => null, 'headline_bold' => null, 'description' => null, 'cta_label' => null, 'bg_color' => null],
        ];
        foreach ($rows as $row) {
            if (! $row['image_path']) {
                continue;
            }
            Banner::updateOrCreate(
                ['title' => $row['title'], 'position' => $row['position']],
                [...$row, 'link_type' => BannerLinkType::None, 'link_value' => null, 'is_active' => true],
            );
        }
    }

    private function copy($disk, string $name): ?string
    {
        $target = 'banners/'.$name;
        if ($disk->exists($target)) {
            return $target;
        }
        $candidates = [
            base_path('../frontend/apps/storefront/public/images/'.$name),
            public_path('images/'.$name),
            storage_path('app/banner-seed/'.$name),
        ];
        foreach ($candidates as $src) {
            if (is_file($src)) {
                $disk->put($target, file_get_contents($src));

                return $target;
            }
        }
        $this->command?->warn("BannerSeeder: source image not found: {$name}");

        return null;
    }
}
