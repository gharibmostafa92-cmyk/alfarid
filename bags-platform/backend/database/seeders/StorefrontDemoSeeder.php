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
use App\Models\ProductVariant;
use App\Models\StockMovement;
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
        foreach (collect($this->products())->pluck('photo')->unique() as $photo) {
            if (! is_file($this->source($photo))) {
                throw new \RuntimeException('Missing demo photograph: '.$photo);
            }
        }

        DB::transaction(function () use ($author): void {
            $branch = Branch::query()->firstOrCreate(['code' => 'DEMO-WEB'], [
                'name' => 'مخزن العرض التجريبي للمتجر', 'type' => BranchType::Warehouse,
                'city' => 'Cairo', 'is_active' => true,
            ]);
            $categories = $this->seedCategories();
            $this->retireLegacyProducts();
            foreach ($this->products() as $row) {
                $this->seedProduct($row, $categories, $branch, $author);
            }
            $this->seedBanners($categories);
        });
        Cache::forget(StoreCatalogService::HOME_CACHE_KEY);
        $this->command?->info('Storefront demo ready: 12 products, 3 slides, 2 promo banners and 1 popup.');
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $defs = [
            'backpacks' => ['en' => 'Backpacks', 'ar' => 'حقائب الظهر', 'parent' => null, 'sort' => 0,
                'desc_en' => 'From daily commutes to weekend escapes — structured backpacks in full-grain textures and modern silhouettes.',
                'desc_ar' => 'من مشاوير الشغل اليومية لرحلات الويك إند — شنط ظهر عملية بخامات فاخرة وتصميمات عصرية.'],
            'laptop-backpacks' => ['en' => 'Laptop Backpacks', 'ar' => 'حقائب اللابتوب', 'parent' => 'backpacks', 'sort' => 0,
                'desc_en' => 'Padded commuter backpacks with dedicated laptop compartments for the modern professional.',
                'desc_ar' => 'شنط ظهر مبطنة بجيب مخصص للابتوب للاستخدام اليومي العملي.'],
            'handbags' => ['en' => 'Handbags', 'ar' => 'حقائب اليد', 'parent' => null, 'sort' => 1,
                'desc_en' => 'Structured handbags and satchels crafted for workdays and evenings alike.',
                'desc_ar' => 'شنط يد منظمة بخامات راقية تناسب الشغل والسهرات.'],
            'crossbody' => ['en' => 'Crossbody', 'ar' => 'حقائب كروس', 'parent' => null, 'sort' => 2,
                'desc_en' => 'Hands-free crossbody bags for days that move with you.',
                'desc_ar' => 'شنط كروس خفيفة وعملية ليوم مليان حركة.'],
            'totes' => ['en' => 'Totes', 'ar' => 'حقائب التسوق', 'parent' => null, 'sort' => 3,
                'desc_en' => 'Roomy totes and weekenders for everyday hauls and short escapes.',
                'desc_ar' => 'شنط واسعة وعملية للمشاوير اليومية والرحلات القصيرة.'],
            'clutches' => ['en' => 'Clutches', 'ar' => 'حقائب السهرة', 'parent' => null, 'sort' => 4,
                'desc_en' => 'Evening clutches and minis for nights that call for polish.',
                'desc_ar' => 'كلاتشات سواريه أنيقة لسهرات مميزة.'],
        ];

        $categories = [];
        foreach ($defs as $key => $def) {
            $categories[$key] = Category::query()->updateOrCreate(['slug' => 'demo-'.$key], [
                'parent_id' => $def['parent'] ? ($categories[$def['parent']]->id ?? Category::query()->where('slug', 'demo-'.$def['parent'])->firstOrFail()->id) : null,
                'name_ar' => $def['ar'], 'name_en' => $def['en'],
                'description_ar' => $def['desc_ar'], 'description_en' => $def['desc_en'],
                'sort_order' => $def['sort'], 'is_active' => true,
            ]);
        }

        return $categories;
    }

    /** Legacy demo products (old 8-photo set) are retired so facets/counts reflect the new 12-product set. */
    private function retireLegacyProducts(): void
    {
        $slugs = collect($this->products())->pluck('slug')->all();
        Product::query()->where('slug', 'like', 'demo-%')->whereNotIn('slug', $slugs)
            ->update(['status' => ProductStatus::Archived->value]);
    }

    /** @param array<string, Category> $categories */
    private function seedProduct(array $row, array $categories, Branch $branch, User $author): void
    {
        $variants = [];
        foreach ($row['colors'] as $color) {
            foreach ($row['sizesFor']($color['code']) as $size) {
                $sku = 'SP-'.$row['code'].'-'.$color['code'].($size['code'] !== '' ? '-'.$size['code'] : '');
                $variants[] = [
                    'sku' => $sku,
                    'color_name_ar' => $color['ar'], 'color_name_en' => $color['en'], 'color_hex' => $color['hex'],
                    'size' => $size['size'], 'capacity' => $size['capacity'],
                    'cost_price' => round($row['price'] * .5), 'retail_price' => $row['price'],
                    'wholesale_price' => round($row['price'] * .8), 'compare_at_price' => $row['compare_at'],
                    'is_active' => true, 'sort_order' => count($variants) + 1,
                ];
            }
        }

        $product = Product::query()->where('slug', $row['slug'])->first();
        if ($product === null) {
            $product = app(ProductService::class)->create([
                'category_id' => $categories[$row['category']]->id,
                'name_ar' => $row['ar'], 'name_en' => $row['en'], 'slug' => $row['slug'],
                'description_ar' => $row['desc_ar'], 'description_en' => $row['desc_en'],
                'sales_channel' => 'both', 'is_featured' => $row['featured'], 'sales_count' => $row['sales'],
                'material_ar' => 'خامات مختلطة', 'material_en' => $row['desc_en'],
                'occasions' => $row['occasions'], 'badge_en' => $row['badge'], 'badge_ar' => null,
                'variants' => $variants,
            ], $author);
        } else {
            $product->update([
                'category_id' => $categories[$row['category']]->id,
                'name_ar' => $row['ar'], 'name_en' => $row['en'],
                'description_ar' => $row['desc_ar'], 'description_en' => $row['desc_en'],
                'material_en' => $row['desc_en'],
                'sales_channel' => 'both', 'is_featured' => $row['featured'], 'sales_count' => $row['sales'],
                'occasions' => $row['occasions'], 'badge_en' => $row['badge'], 'badge_ar' => null,
            ]);
            foreach ($variants as $data) {
                ProductVariant::query()->updateOrCreate(['sku' => $data['sku']], [...$data, 'product_id' => $product->id]);
            }
            $product->refresh();
        }

        if ($product->getMedia('images')->isEmpty()) {
            $product->addMedia($this->source($row['photo']))->preservingOriginal()
                ->withCustomProperties(['storefront_demo' => true])
                ->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
        }
        app(ProductPublishabilityChecker::class)->assertPublishable($product);
        $product->update(['status' => ProductStatus::Published, 'published_at' => $this->publishedAt($row)]);

        foreach ($product->variants()->where('is_active', true)->get() as $variant) {
            if ($variant->getMedia('images')->isEmpty()) {
                $photo = $row['color_photos'][$variant->color_name_en] ?? $row['photo'];
                $variant->addMedia($this->source($photo))->preservingOriginal()
                    ->withCustomProperties(['storefront_demo' => true])
                    ->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
            }
            if ($row['stock'] <= 0) {
                continue;
            }
            $exists = StockMovement::query()->where('product_variant_id', $variant->id)->where('branch_id', $branch->id)->exists();
            if ($exists) {
                continue;
            }
            app(InventoryService::class)->recordMovement([
                'product_variant_id' => $variant->id,
                'branch_id' => $branch->id, 'type' => StockMovementType::AdjustmentIn,
                'affected_bucket' => StockBucket::OnHand, 'quantity' => $row['stock'],
                'unit_cost' => round($row['price'] * .5), 'created_by' => $author->id,
                'notes' => 'STOREFRONT DEMO: local preview opening stock only.',
            ]);
        }
    }

    private function publishedAt(array $row): \DateTimeImmutable|\DateTime
    {
        return $row['old_pub'] ? now()->subDays(60) : now();
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        $rose = ['code' => 'ROSE', 'en' => 'Rose', 'ar' => 'وردي', 'hex' => '#e89aa8'];
        $noir = ['code' => 'NOIR', 'en' => 'Noir', 'ar' => 'أسود', 'hex' => '#1a1a1a'];
        $camel = ['code' => 'CAMEL', 'en' => 'Camel', 'ar' => 'جملي', 'hex' => '#d4b38d'];
        $slate = ['code' => 'SLATE', 'en' => 'Slate', 'ar' => 'رمادي أزرق', 'hex' => '#4f7d88'];
        $cream = ['code' => 'CREAM', 'en' => 'Cream', 'ar' => 'كريمي', 'hex' => '#ede3de'];
        $m14 = ['code' => 'M', 'size' => 'Medium', 'capacity' => '14L'];
        $l20 = ['code' => 'L', 'size' => 'Large', 'capacity' => '20L'];
        $m18 = ['code' => 'M', 'size' => 'Medium', 'capacity' => '18L'];
        $s10 = ['code' => 'S', 'size' => 'Small', 'capacity' => '10L'];
        $os22 = ['code' => 'OS', 'size' => 'One Size', 'capacity' => '22L'];
        $os40 = ['code' => 'OS', 'size' => 'One Size', 'capacity' => '40L'];
        $nosize = ['code' => '', 'size' => null, 'capacity' => null];
        $genericAr = 'منتج تجريبي لمعاينة تصميم المتجر فقط، وليس معروضًا للبيع الفعلي.';

        return [
            ['slug' => 'demo-vogue-elegance-backpack', 'code' => 'VG', 'category' => 'backpacks',
                'en' => "Vogue Elegance Women's Backpack", 'ar' => 'حقيبة ظهر فوغ أنيقة',
                'desc_en' => 'The Vogue Elegance combines supple faux leather with ergonomic padded straps, interior tablet organizer, and polished rose-gold hardware.',
                'desc_ar' => $genericAr, 'price' => 1250, 'compare_at' => null, 'stock' => 10,
                'colors' => [$rose, $camel], 'sizesFor' => fn (string $c) => $c === 'CAMEL' ? [$m14] : [$m14, $l20],
                'color_photos' => ['Rose' => 'everyday-backpack', 'Camel' => 'travel-backpack'],
                'occasions' => ['casual', 'work'], 'badge' => null, 'featured' => true, 'sales' => 100, 'old_pub' => false, 'photo' => 'everyday-backpack'],
            ['slug' => 'demo-urban-chic-laptop-backpack', 'code' => 'UC', 'category' => 'laptop-backpacks',
                'en' => 'Urban Chic Laptop Backpack', 'ar' => 'حقيبة لابتوب عصرية',
                'desc_en' => 'Engineered for daily urban commuters featuring a padded 15-inch laptop compartment, weather-resistant zip seals, and hidden passport security pocket.',
                'desc_ar' => $genericAr, 'price' => 1190, 'compare_at' => null, 'stock' => 10,
                'colors' => [$noir, $slate], 'sizesFor' => fn (string $c) => [$os22],
                'occasions' => ['work'], 'badge' => 'BESTSELLER', 'featured' => true, 'sales' => 95, 'old_pub' => false, 'photo' => 'city-backpack'],
            ['slug' => 'demo-travel-lite-backpack', 'code' => 'TL', 'category' => 'backpacks',
                'en' => 'Travel Lite Anti-Theft Backpack', 'ar' => 'حقيبة ظهر مضادة للسرقة',
                'desc_en' => 'Sleek travel companion crafted from reinforced anti-slash canvas with RFID shielding pockets and effortless luggage strap attachment.',
                'desc_ar' => $genericAr, 'price' => 1200, 'compare_at' => 1500, 'stock' => 10,
                'colors' => [$camel, $noir], 'sizesFor' => fn (string $c) => [$m18],
                'occasions' => ['work', 'weekend'], 'badge' => null, 'featured' => false, 'sales' => 70, 'old_pub' => false, 'photo' => 'travel-backpack'],
            ['slug' => 'demo-classic-fashion-backpack', 'code' => 'CF', 'category' => 'backpacks',
                'en' => 'Classic Fashion Backpack', 'ar' => 'حقيبة ظهر كلاسيكية',
                'desc_en' => 'Timeless silhouette reimagined with premium calfskin texture, dual slip pockets, and adjustable shoulder straps.',
                'desc_ar' => $genericAr, 'price' => 890, 'compare_at' => null, 'stock' => 10,
                'colors' => [$cream, $rose], 'sizesFor' => fn (string $c) => [$m14],
                'occasions' => ['casual'], 'badge' => null, 'featured' => false, 'sales' => 45, 'old_pub' => false, 'photo' => 'everyday-backpack'],
            ['slug' => 'demo-soft-rose-daypack', 'code' => 'SR', 'category' => 'backpacks',
                'en' => 'Soft Rose Daypack', 'ar' => 'حقيبة ظهر وردية صغيرة',
                'desc_en' => 'Featherlight construction with premium coated fabric, magnetic front pouch, and contoured breathable mesh back.',
                'desc_ar' => $genericAr, 'price' => 750, 'compare_at' => null, 'stock' => 0,
                'colors' => [$rose], 'sizesFor' => fn (string $c) => [$s10],
                'occasions' => ['casual', 'weekend'], 'badge' => null, 'featured' => false, 'sales' => 40, 'old_pub' => false, 'photo' => 'city-backpack'],
            ['slug' => 'demo-parisian-crossbody', 'code' => 'PC', 'category' => 'crossbody',
                'en' => 'Parisian Leather Crossbody Bag', 'ar' => 'حقيبة كروس جلد باريسية',
                'desc_en' => 'Handcrafted in soft structured leather, featuring an iconic golden lock clasp, multiple inner compartments, and detachable shoulder chain.',
                'desc_ar' => $genericAr, 'price' => 1650, 'compare_at' => null, 'stock' => 10,
                'colors' => [$cream, $noir], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['casual', 'evening'], 'badge' => 'TRENDING', 'featured' => false, 'sales' => 80, 'old_pub' => false, 'photo' => 'leather-tote'],
            ['slug' => 'demo-mini-pastel-crossbody', 'code' => 'MP', 'category' => 'crossbody',
                'en' => 'Mini Pastel Crossbody', 'ar' => 'حقيبة كروس باستيل صغيرة',
                'desc_en' => 'Heritage equestrian-inspired curved flap with antique brass ring hardware and soft micro-suede interior lining.',
                'desc_ar' => $genericAr, 'price' => 690, 'compare_at' => null, 'stock' => 10,
                'colors' => [$rose, $cream], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['casual'], 'badge' => null, 'featured' => false, 'sales' => 55, 'old_pub' => false, 'photo' => 'mini-crossbody'],
            ['slug' => 'demo-saffiano-handbag', 'code' => 'SF', 'category' => 'handbags',
                'en' => 'Saffiano Structured Handbag', 'ar' => 'حقيبة يد سافيانو منظمة',
                'desc_en' => 'Executive poise embodied in scratch-resistant Saffiano finish, reinforced top handles, protective metal feet, and organized center divider.',
                'desc_ar' => $genericAr, 'price' => 2150, 'compare_at' => null, 'stock' => 10,
                'colors' => [$noir, $camel], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['work', 'evening'], 'badge' => 'LUXE', 'featured' => true, 'sales' => 120, 'old_pub' => false, 'photo' => 'rose-handbag'],
            ['slug' => 'demo-city-top-handle', 'code' => 'CT', 'category' => 'handbags',
                'en' => 'City Top Handle Bag', 'ar' => 'حقيبة يد بمقبض علوي',
                'desc_en' => 'Executive poise embodied in scratch-resistant Saffiano finish, reinforced top handles, protective metal feet, and organized center divider.',
                'desc_ar' => $genericAr, 'price' => 1490, 'compare_at' => null, 'stock' => 10,
                'colors' => [$slate], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['work'], 'badge' => null, 'featured' => false, 'sales' => 50, 'old_pub' => true, 'photo' => 'classic-handbag'],
            ['slug' => 'demo-everyday-tote', 'code' => 'ET', 'category' => 'totes',
                'en' => 'The Everyday Tote', 'ar' => 'حقيبة التسوق اليومية',
                'desc_en' => 'Spacious everyday tote harmonizing heavy-duty woven cotton with bridle leather handles. Perfect for farmers market trips and beach escapes.',
                'desc_ar' => $genericAr, 'price' => 1250, 'compare_at' => 1550, 'stock' => 10,
                'colors' => [$camel, $cream], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['casual', 'work'], 'badge' => null, 'featured' => false, 'sales' => 65, 'old_pub' => true, 'photo' => 'classic-handbag'],
            ['slug' => 'demo-executive-weekender', 'code' => 'EW', 'category' => 'totes',
                'en' => 'Executive Weekender Duffel Tote', 'ar' => 'حقيبة سفر تنفيذية كبيرة',
                'desc_en' => 'Substantial carry-all crafted for short escapes and high-powered conferences. Includes ventilated shoe cabin and water-resistant lining.',
                'desc_ar' => $genericAr, 'price' => 2400, 'compare_at' => null, 'stock' => 10,
                'colors' => [$slate, $noir], 'sizesFor' => fn (string $c) => [$os40],
                'occasions' => ['weekend'], 'badge' => 'EXCLUSIVE', 'featured' => true, 'sales' => 90, 'old_pub' => false, 'photo' => 'weekend-bag'],
            ['slug' => 'demo-quilted-clutch', 'code' => 'QC', 'category' => 'clutches',
                'en' => 'Quilted Velvet Evening Clutch', 'ar' => 'كلاتش مخمل مبطن للسهرة',
                'desc_en' => 'Luxurious velvet quilting paired with a removable woven metallic chain strap, making an unforgettable statement at gala evenings.',
                'desc_ar' => $genericAr, 'price' => 1050, 'compare_at' => 1400, 'stock' => 10,
                'colors' => [$noir], 'sizesFor' => fn (string $c) => [$nosize],
                'occasions' => ['evening'], 'badge' => null, 'featured' => false, 'sales' => 60, 'old_pub' => true, 'photo' => 'leather-tote'],
        ];
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
}
