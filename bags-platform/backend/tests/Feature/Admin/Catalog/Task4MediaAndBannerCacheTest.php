<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\BannerService;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\StoreCatalogService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Task4MediaAndBannerCacheTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('r2_public');
        Storage::fake('local');
    }

    public function test_reorder_asserts_ownership_before_checking_expected_ids(): void
    {
        $category = Category::factory()->create();
        $product1 = Product::factory()->create(['category_id' => $category->id]);
        $product2 = Product::factory()->create(['category_id' => $category->id]);

        $media1 = $product1->addMedia(UploadedFile::fake()->image('p1.jpg'))->toMediaCollection('images');
        $media2 = $product2->addMedia(UploadedFile::fake()->image('p2.jpg'))->toMediaCollection('images');

        $service = app(ProductMediaService::class);

        // Supplying media2 (belongs to product2) to product1 reorder must abort 404 on ownership check
        // even if expectedIds matches current IDs.
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('ملف غير تابع لهذا المنتج');

        $service->reorder($product1, [$media2->id], [$media1->id]);
    }

    public function test_reorder_and_delete_invalidate_storefront_caches(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $media1 = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('images');
        $media2 = $product->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('images');

        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['dummy-data'], 3600);
        $this->assertTrue(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        $service = app(ProductMediaService::class);
        $service->reorder($product, [$media2->id, $media1->id]);

        $this->assertFalse(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['dummy-data'], 3600);
        $this->assertTrue(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        $service->delete($product, $media2);
        $this->assertFalse(Cache::has(StoreCatalogService::HOME_CACHE_KEY));
    }

    public function test_banner_mutations_invalidate_storefront_caches(): void
    {
        $service = app(BannerService::class);

        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['banner-test'], 3600);
        $this->assertTrue(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        $banner = $service->create([
            'title' => 'Test Banner',
            'position' => 'main_slider',
        ], UploadedFile::fake()->image('banner.jpg'));

        $this->assertFalse(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['banner-test'], 3600);
        $service->setActive($banner, false);
        $this->assertFalse(Cache::has(StoreCatalogService::HOME_CACHE_KEY));

        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['banner-test'], 3600);
        $service->delete($banner);
        $this->assertFalse(Cache::has(StoreCatalogService::HOME_CACHE_KEY));
    }
}
