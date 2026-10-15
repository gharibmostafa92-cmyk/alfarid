<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SalesChannel;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * بيانات كتالوج تجريبية: أقسام وبراندات ومنتجات مسودة (من غير صور، فمبتتنشرش).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $brands = $this->seedBrands();
        $this->seedProducts($categories, $brands);
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $tree = [
            ['name_ar' => 'شنط يد', 'name_en' => 'Handbags', 'slug' => 'handbags'],
            ['name_ar' => 'محافظ', 'name_en' => 'Wallets', 'slug' => 'wallets'],
            ['name_ar' => 'شنط سفر', 'name_en' => 'Luggage', 'slug' => 'luggage', 'children' => [
                ['name_ar' => 'كابينة', 'name_en' => 'Cabin', 'slug' => 'cabin'],
                ['name_ar' => 'متوسطة', 'name_en' => 'Medium', 'slug' => 'medium'],
                ['name_ar' => 'كبيرة', 'name_en' => 'Large', 'slug' => 'large'],
            ]],
            ['name_ar' => 'شنط ظهر', 'name_en' => 'Backpacks', 'slug' => 'backpacks'],
        ];

        $categories = [];
        foreach ($tree as $i => $node) {
            $parent = Category::firstOrCreate(
                ['slug' => $node['slug']],
                ['name_ar' => $node['name_ar'], 'name_en' => $node['name_en'], 'sort_order' => $i, 'is_active' => true],
            );
            $categories[$node['slug']] = $parent;
            foreach ($node['children'] ?? [] as $j => $child) {
                $categories[$child['slug']] = Category::firstOrCreate(
                    ['slug' => $child['slug']],
                    ['parent_id' => $parent->id, 'name_ar' => $child['name_ar'], 'name_en' => $child['name_en'], 'sort_order' => $j, 'is_active' => true],
                );
            }
        }

        return $categories;
    }

    /** @return array<string, Brand> */
    private function seedBrands(): array
    {
        $brands = [];
        foreach ([
            ['name_ar' => 'ميلانو', 'name_en' => 'Milano', 'slug' => 'milano'],
            ['name_ar' => 'فوياجر', 'name_en' => 'Voyager', 'slug' => 'voyager'],
            ['name_ar' => 'أطلس', 'name_en' => 'Atlas', 'slug' => 'atlas'],
            ['name_ar' => 'لونا', 'name_en' => 'Luna', 'slug' => 'luna'],
        ] as $i => $brand) {
            $brands[$brand['slug']] = Brand::firstOrCreate(
                ['slug' => $brand['slug']],
                ['name_ar' => $brand['name_ar'], 'name_en' => $brand['name_en'], 'is_active' => true],
            );
        }

        return $brands;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     */
    private function seedProducts(array $categories, array $brands): void
    {
        if (Product::query()->exists()) {
            return; // البيانات التجريبية بتتزرع مرة واحدة بس
        }

        $author = User::query()->first() ?? User::factory()->create([
            'name' => 'Catalog Demo',
            'email' => 'catalog-demo@alfarid.test',
            'password' => Hash::make('password'),
        ]);

        $service = app(ProductService::class);
        foreach ($this->catalog($categories, $brands) as $data) {
            $service->create($data, $author);
        }
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     * @return list<array<string, mixed>>
     */
    private function catalog(array $categories, array $brands): array
    {
        $variant = fn (string $ar, string $en, string $hex, float $cost, float $retail, float $wholesale, array $extra = []) => array_merge([
            'color_name_ar' => $ar, 'color_name_en' => $en, 'color_hex' => $hex,
            'cost_price' => $cost, 'retail_price' => $retail, 'wholesale_price' => $wholesale,
        ], $extra);
        $black = ['أسود', 'Black', '#000000'];
        $brown = ['بني', 'Brown', '#8B4513'];

        return [
            [
                'category_id' => $categories['handbags']->id, 'brand_id' => $brands['milano']->id,
                'name_ar' => 'حقيبة يد جلد كلاسيك', 'name_en' => 'Classic Leather Handbag',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.9,
                'variants' => [
                    $variant(...$black, cost: 800, retail: 1450, wholesale: 1200),
                    $variant(...$brown, cost: 800, retail: 1450, wholesale: 1200),
                ],
            ],
            [
                'category_id' => $categories['handbags']->id, 'brand_id' => $brands['luna']->id,
                'name_ar' => 'حقيبة كروس صغيرة', 'name_en' => 'Mini Crossbody Bag',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.4,
                'variants' => [
                    $variant('وردي', 'Pink', '#E8A2B4', 350, 690, 560),
                    $variant('بيج', 'Beige', '#D9C7A7', 350, 690, 560),
                    $variant(...$black, cost: 350, retail: 690, wholesale: 560),
                ],
            ],
            [
                'category_id' => $categories['wallets']->id, 'brand_id' => $brands['atlas']->id,
                'name_ar' => 'محفظة جلد طبيعي', 'name_en' => 'Leather Bifold Wallet',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.15,
                'variants' => [
                    $variant(...$black, cost: 220, retail: 450, wholesale: 370),
                    $variant(...$brown, cost: 220, retail: 450, wholesale: 370),
                ],
            ],
            [
                'category_id' => $categories['wallets']->id, 'brand_id' => $brands['milano']->id,
                'name_ar' => 'محفظة كروت', 'name_en' => 'Card Holder',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.08,
                'variants' => [
                    $variant(...$black, cost: 120, retail: 260, wholesale: 210),
                    $variant('جملي', 'Tan', '#C68B4E', 120, 260, 210),
                    $variant('كحلي', 'Navy', '#1F2A5A', 120, 260, 210),
                ],
            ],
            [
                'category_id' => $categories['cabin']->id, 'brand_id' => $brands['voyager']->id,
                'name_ar' => 'حقيبة سفر كابينة 20 بوصة', 'name_en' => 'Cabin Trolley 20',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 2.6,
                'variants' => [
                    $variant('فضي', 'Silver', '#C0C0C0', 1500, 2750, 2300, ['size' => '20"']),
                    $variant('أزرق', 'Blue', '#2563EB', 1500, 2750, 2300, ['size' => '20"']),
                ],
            ],
            [
                'category_id' => $categories['medium']->id, 'brand_id' => $brands['voyager']->id,
                'name_ar' => 'حقيبة سفر متوسطة 24 بوصة', 'name_en' => 'Medium Suitcase 24',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 3.4,
                'variants' => [
                    $variant(...$black, cost: 1900, retail: 3450, wholesale: 2900, extra: ['size' => '24"']),
                    $variant('أحمر', 'Red', '#B91C1C', 1900, 3450, 2900, ['size' => '24"']),
                    $variant('رمادي', 'Gray', '#6B7280', 1900, 3450, 2900, ['size' => '24"']),
                ],
            ],
            [
                'category_id' => $categories['large']->id, 'brand_id' => $brands['atlas']->id,
                'name_ar' => 'حقيبة سفر كبيرة 28 بوصة', 'name_en' => 'Large Suitcase 28',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 4.2,
                'variants' => [
                    $variant(...$black, cost: 2300, retail: 4200, wholesale: 3500, extra: ['size' => '28"']),
                    $variant('أزرق', 'Blue', '#2563EB', 2300, 4200, 3500, ['size' => '28"']),
                ],
            ],
            [
                'category_id' => $categories['backpacks']->id, 'brand_id' => $brands['voyager']->id,
                'name_ar' => 'حقيبة ظهر سفر 30 لتر', 'name_en' => 'Travel Backpack 30L',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 1.1,
                'variants' => [
                    $variant('زيتي', 'Olive', '#556B2F', 600, 1150, 950, ['capacity' => '30L']),
                    $variant(...$black, cost: 600, retail: 1150, wholesale: 950, extra: ['capacity' => '30L']),
                ],
            ],
            [
                'category_id' => $categories['backpacks']->id, 'brand_id' => $brands['atlas']->id,
                'name_ar' => 'حقيبة ظهر لابتوب', 'name_en' => 'Laptop Backpack',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.95,
                'variants' => [
                    $variant('رمادي', 'Gray', '#6B7280', 520, 990, 820, ['capacity' => '15L']),
                    $variant('كحلي', 'Navy', '#1F2A5A', 520, 990, 820, ['capacity' => '15L']),
                    $variant(...$black, cost: 520, retail: 990, wholesale: 820, extra: ['capacity' => '15L']),
                ],
            ],
            [
                'category_id' => $categories['handbags']->id, 'brand_id' => $brands['luna']->id,
                'name_ar' => 'حقيبة توت قماش', 'name_en' => 'Canvas Tote Bag',
                'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.3,
                'variants' => [
                    $variant('بيج', 'Beige', '#D9C7A7', 180, 390, 320),
                    $variant('أبيض', 'White', '#FFFFFF', 180, 390, 320),
                ],
            ],
        ];
    }
}
