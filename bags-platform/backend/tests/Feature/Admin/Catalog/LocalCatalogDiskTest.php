<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductMediaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalCatalogDiskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_image_upload_uses_the_configured_catalog_disk(): void
    {
        config(['filesystems.catalog_disk' => 'public']);
        Storage::fake('public');

        $category = Category::factory()->create();
        $media = $category->addMedia(UploadedFile::fake()->image('category.png'))->toMediaCollection('image');

        $this->assertSame('public', $media->disk);
        $this->assertSame('public', $media->conversions_disk);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_product_image_upload_uses_the_configured_catalog_disk(): void
    {
        config(['filesystems.catalog_disk' => 'public']);
        Storage::fake('public');

        $product = Product::factory()->create();
        $media = app(ProductMediaService::class)->upload(
            $product,
            ['collection' => 'images'],
            [UploadedFile::fake()->image('product.png')],
        );

        $this->assertCount(1, $media);
        $this->assertSame('public', $media->first()->disk);
        $this->assertSame('public', $media->first()->conversions_disk);
        Storage::disk('public')->assertExists($media->first()->getPathRelativeToRoot());
    }

    public function test_category_image_failure_returns_422_with_retry_context_instead_of_success(): void
    {
        config(['filesystems.catalog_disk' => 'invalid-disk']);

        try {
            app(CategoryService::class)->create(
                Category::factory()->make()->only(['parent_id', 'name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active']),
                UploadedFile::fake()->image('category.png'),
            );
            $this->fail('Expected the failed image upload to throw instead of reporting success.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('category_image_failed', $e->errorCode);
            $this->assertArrayHasKey('category_id', $e->errors);
        }

        // The row is kept (retry from the edit page), but the response is an
        // error — never a success message.
        $this->assertDatabaseCount('categories', 1);
    }
}
