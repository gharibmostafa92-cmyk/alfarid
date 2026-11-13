<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\BrandService;
use App\Services\Catalog\CategoryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class Task4SoftDeletedProductCascadeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_soft_deleted_products_allow_category_and_brand_deletion(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);
        $product->delete(); // Soft delete

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        app(CategoryService::class)->delete($category);
        app(BrandService::class)->delete($brand);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
    }

    public function test_restoring_product_with_deleted_category_is_prevented(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $product->delete();

        app(CategoryService::class)->delete($category);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('لا يمكن استعادة منتج يتبع لقسم محذوف');

        $product->restore();
    }

    public function test_restoring_product_with_deleted_brand_is_prevented(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);
        $product->delete();

        app(BrandService::class)->delete($brand);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('لا يمكن استعادة منتج يتبع لعلامة تجارية محذوفة');

        $product->restore();
    }
}
