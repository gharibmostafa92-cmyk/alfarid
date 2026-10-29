<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Load-test dataset: 2000 published products with 2 variants each plus stock.
 *
 * Explicitly invoked for baseline/load measurements only:
 *   php artisan db:seed --class=LoadTestSeeder --force
 * Never called from DatabaseSeeder. Refuses to run in production.
 */
class LoadTestSeeder extends Seeder
{
    public const TARGET_PRODUCTS = 2000;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('LoadTestSeeder cannot run in production.');
        }

        $existing = Product::query()->where('status', ProductStatus::Published)->count();
        if ($existing >= self::TARGET_PRODUCTS) {
            $this->command?->info("Already have {$existing} published products; skipping.");

            return;
        }

        $need = self::TARGET_PRODUCTS - $existing;

        $branch = Branch::query()->first() ?? Branch::factory()->create(['is_active' => true]);
        $location = Location::query()->where('branch_id', $branch->id)->first()
            ?? Location::factory()->create(['branch_id' => $branch->id]);

        $categoryIds = Category::query()->pluck('id')->all();
        $brandIds = Brand::query()->pluck('id')->all();
        if ($categoryIds === [] || $brandIds === []) {
            throw new \RuntimeException('Seed base catalog (categories/brands) before LoadTestSeeder.');
        }

        $this->command?->info("Seeding {$need} load-test products…");

        $made = 0;
        for ($i = 1; $i <= $need; $i++) {
            $n = $existing + $i;
            $slugBase = 'load-test-product-'.$n;
            $product = Product::factory()->create([
                'category_id' => $categoryIds[array_rand($categoryIds)],
                'brand_id' => $brandIds[array_rand($brandIds)],
                'code' => 'LT'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'name_ar' => 'منتج اختبار الحمل '.$n,
                'name_en' => 'Load Test Product '.$n,
                'slug' => $slugBase.'-'.Str::random(6),
                'status' => ProductStatus::Published,
                'published_at' => now(),
                'sales_channel' => SalesChannel::Both,
            ]);

            for ($v = 0; $v < 2; $v++) {
                $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
                StockLevel::forceCreate([
                    'product_variant_id' => $variant->id,
                    'branch_id' => $branch->id,
                    'location_id' => $location->id,
                    'on_hand' => random_int(5, 50),
                    'reserved' => 0,
                    'damaged' => 0,
                ]);
            }

            $made++;
            if ($made % 250 === 0) {
                $this->command?->info("  …{$made}/{$need}");
            }
        }

        $this->command?->info("Done: {$made} products.");
    }
}
