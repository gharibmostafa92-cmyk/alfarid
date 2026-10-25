<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Enums\BranchType;
use App\Enums\ProductStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductPublishabilityChecker;
use App\Services\Catalog\ProductService;
use App\Services\InventoryService;
use App\Services\StoreCatalogService;
use App\Support\BannerImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorefrontDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Storefront demo data is only available in local/testing environments.');
        }
        if (BannerImage::disk() !== 'public') {
            throw new \RuntimeException('Set BANNERS_DISK=public before loading the local storefront demo.');
        }
        $author = User::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        foreach ($this->products() as $row) {
            if (! is_file($this->source($row['image']))) {
                throw new \RuntimeException('Missing demo photograph: '.$row['image']);
            }
        }

        DB::transaction(function () use ($author): void {
            $branch = Branch::query()->firstOrCreate(['code' => 'DEMO-WEB'], [
                'name' => 'مخزن العرض التجريبي للمتجر', 'type' => BranchType::Warehouse,
                'city' => 'Cairo', 'is_active' => true,
            ]);
            $categories = [];
            foreach ([
                'handbags' => ['شنط يد — تجريبي', 'Handbags'],
                'backpacks' => ['شنط ظهر — تجريبي', 'Backpacks'],
                'totes' => ['شنط تسوق — تجريبي', 'Totes & Travel'],
            ] as $key => [$arabic, $english]) {
                $categories[$key] = Category::query()->firstOrCreate(['slug' => 'demo-'.$key], [
                    'name_ar' => $arabic, 'name_en' => $english, 'sort_order' => count($categories), 'is_active' => true,
                ]);
            }
            foreach ($this->products() as $index => $row) {
                $product = Product::query()->where('slug', 'demo-'.$row['image'])->first();
                if ($product) {
                    continue;
                }
                $product = app(ProductService::class)->create([
                    'category_id' => $categories[$row['category']]->id,
                    'name_ar' => $row['ar'], 'name_en' => $row['en'], 'slug' => 'demo-'.$row['image'],
                    'description_ar' => 'منتج تجريبي لمعاينة تصميم المتجر فقط، وليس معروضًا للبيع الفعلي.',
                    'description_en' => 'Sample product for storefront design review. Not a real product offer.',
                    'sales_channel' => 'both', 'is_featured' => $index < 4, 'sales_count' => 80 - $index * 7,
                    'material_ar' => 'خامات مختلطة', 'material_en' => 'Mixed materials',
                    'variants' => [[
                        'color_name_ar' => $row['color_ar'], 'color_name_en' => $row['color'], 'color_hex' => $row['hex'],
                        'cost_price' => round($row['price'] * .5), 'retail_price' => $row['price'],
                        'wholesale_price' => round($row['price'] * .8), 'compare_at_price' => $row['old'],
                        'is_active' => true, 'sort_order' => 1,
                    ]],
                ], $author);
                $product->addMedia($this->source($row['image']))->preservingOriginal()
                    ->withCustomProperties(['storefront_demo' => true])
                    ->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
                app(ProductPublishabilityChecker::class)->assertPublishable($product);
                $product->update(['status' => ProductStatus::Published, 'published_at' => now()]);
                if ($row['stock'] > 0) {
                    app(InventoryService::class)->recordMovement([
                        'product_variant_id' => $product->variants()->firstOrFail()->id,
                        'branch_id' => $branch->id, 'type' => StockMovementType::AdjustmentIn,
                        'affected_bucket' => StockBucket::OnHand, 'quantity' => $row['stock'],
                        'unit_cost' => round($row['price'] * .5), 'created_by' => $author->id,
                        'notes' => 'STOREFRONT DEMO: local preview opening stock only.',
                    ]);
                }
            }
            $this->seedBanners($categories);
        });
        Cache::forget(StoreCatalogService::HOME_CACHE_KEY);
        $this->command?->info('Storefront demo ready: 8 products, 3 slides, 2 promo banners and 1 popup.');
    }

    /** @param array<string, Category> $categories */
    private function seedBanners(array $categories): void
    {
        $rows = [
            ['title' => 'DEMO · The Everyday Edit', 'position' => BannerPosition::MainSlider, 'sort_order' => 1,
                'photo' => 'classic-handbag', 'category' => 'totes', 'kicker' => 'THE EVERYDAY EDIT',
                'headline' => 'MADE FOR', 'headline_bold' => 'YOUR EVERYDAY',
                'description' => 'A little structure. A little softness. Find the bag that feels like you.', 'cta_label' => 'EXPLORE THE EDIT'],
            ['title' => 'DEMO · City Essentials', 'position' => BannerPosition::MainSlider, 'sort_order' => 2,
                'photo' => 'rose-handbag', 'category' => 'handbags', 'kicker' => 'NEW SEASON / NEW SHAPES',
                'headline' => 'SMALL BAG.', 'headline_bold' => 'BIG ENERGY.',
                'description' => 'Compact silhouettes, considered details, and room for your daily essentials.', 'cta_label' => 'DISCOVER MORE'],
            ['title' => 'DEMO · Weekend Collection', 'position' => BannerPosition::MainSlider, 'sort_order' => 3,
                'photo' => 'weekend-bag', 'category' => 'handbags'],
            ['title' => 'DEMO · Everyday Backpacks', 'position' => BannerPosition::MiddleBanner, 'sort_order' => 1,
                'photo' => 'everyday-backpack', 'category' => 'backpacks', 'kicker' => 'EVERYDAY ESSENTIALS',
                'headline_bold' => 'Backpacks', 'cta_label' => 'View collection', 'bg_color' => '#dfe6ec'],
            ['title' => 'DEMO · The Crossbody Edit', 'position' => BannerPosition::MiddleBanner, 'sort_order' => 2,
                'photo' => 'rose-handbag', 'category' => 'handbags', 'kicker' => 'CARRY SOMETHING LOVELY',
                'headline_bold' => 'Crossbody Edit', 'cta_label' => 'Discover the edit', 'bg_color' => '#ede3de'],
            ['title' => 'DEMO · New Season Preview', 'position' => BannerPosition::Popup, 'sort_order' => 1,
                'photo' => 'weekend-bag', 'category' => 'handbags'],
        ];
        foreach ($rows as $row) {
            $path = 'banners/storefront-demo/'.sha1($row['title']).'.jpg';
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, file_get_contents($this->source($row['photo'])));
            }
            $category = $categories[$row['category']];
            unset($row['category'], $row['photo']);
            Banner::query()->firstOrCreate(['title' => $row['title'], 'position' => $row['position']], [
                ...$row, 'image_path' => $path, 'link_type' => BannerLinkType::Category,
                'link_value' => (string) $category->id, 'is_active' => true,
            ]);
        }
    }

    private function source(string $name): string
    {
        return base_path('../frontend/apps/storefront/public/images/demo/'.$name.'.jpg');
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        return [
            ['image' => 'classic-handbag', 'category' => 'totes', 'ar' => 'حقيبة تسوق يومية', 'en' => 'The Everyday Tote', 'price' => 1250, 'old' => 1550, 'stock' => 16, 'color_ar' => 'بيج', 'color' => 'Sand', 'hex' => '#cbb791'],
            ['image' => 'rose-handbag', 'category' => 'handbags', 'ar' => 'حقيبة يد كلاسيكية', 'en' => 'City Top Handle Bag', 'price' => 1490, 'old' => null, 'stock' => 12, 'color_ar' => 'أحمر', 'color' => 'Coral', 'hex' => '#c34338'],
            ['image' => 'city-backpack', 'category' => 'backpacks', 'ar' => 'حقيبة ظهر للمدينة', 'en' => 'The City Backpack', 'price' => 890, 'old' => 1100, 'stock' => 24, 'color_ar' => 'كحلي', 'color' => 'Navy', 'hex' => '#24364a'],
            ['image' => 'weekend-bag', 'category' => 'handbags', 'ar' => 'حقيبة نهاية الأسبوع', 'en' => 'Weekend Woven Satchel', 'price' => 1850, 'old' => 2250, 'stock' => 8, 'color_ar' => 'جملي', 'color' => 'Tan', 'hex' => '#b9783e'],
            ['image' => 'mini-crossbody', 'category' => 'handbags', 'ar' => 'حقيبة كروس صغيرة', 'en' => 'Mini Pastel Crossbody', 'price' => 690, 'old' => null, 'stock' => 18, 'color_ar' => 'وردي', 'color' => 'Blush', 'hex' => '#e6b0b6'],
            ['image' => 'everyday-backpack', 'category' => 'backpacks', 'ar' => 'حقيبة ظهر وردية', 'en' => 'Soft Rose Daypack', 'price' => 950, 'old' => 1190, 'stock' => 14, 'color_ar' => 'وردي ترابي', 'color' => 'Dusty Rose', 'hex' => '#b77777'],
            ['image' => 'leather-tote', 'category' => 'handbags', 'ar' => 'حقيبة كروس مبطنة', 'en' => 'Quilted Evening Crossbody', 'price' => 1650, 'old' => null, 'stock' => 6, 'color_ar' => 'أسود', 'color' => 'Black', 'hex' => '#171717'],
            ['image' => 'travel-backpack', 'category' => 'backpacks', 'ar' => 'حقيبة ظهر للسفر', 'en' => 'Lightweight Travel Pack', 'price' => 790, 'old' => null, 'stock' => 0, 'color_ar' => 'أسود', 'color' => 'Black', 'hex' => '#222222'],
        ];
    }
}
