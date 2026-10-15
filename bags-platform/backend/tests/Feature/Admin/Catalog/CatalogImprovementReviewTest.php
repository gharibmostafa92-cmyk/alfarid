<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\BrandService;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantService;
use App\Services\Media\UuidPathGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogImprovementReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $permissions = ['products.create', 'products.view', 'products.update', 'products.publish', 'categories.view', 'categories.update', 'brands.view'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);
        $this->withToken($staff->createToken('review')->plainTextToken);
    }

    public function test_multi_image_failure_removes_originals_and_conversions_even_for_the_failing_item(): void
    {
        $product = Product::factory()->create();
        $real = app(FileManipulator::class);
        $calls = 0;
        $this->mock(FileManipulator::class)->shouldReceive('createDerivedFiles')->twice()
            ->andReturnUsing(function (Media $media) use ($real, &$calls): void {
                $real->createDerivedFiles($media);
                if (++$calls === 2) {
                    throw new RuntimeException('Conversion failed after writing files');
                }
            });
        try {
            app(ProductMediaService::class)->upload($product, ['collection' => 'images'], [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]);
            $this->fail('Expected conversion failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Conversion failed after writing files', $exception->getMessage());
        }
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_replacement_keeps_old_brand_and_category_files_and_database_values(): void
    {
        foreach ([BrandService::class => [Brand::class, 'logo'], CategoryService::class => [Category::class, 'image']] as $service => [$model, $collection]) {
            $this->app->forgetInstance(FileManipulator::class);
            $record = $model::factory()->create(['name_en' => 'Original']);
            $old = $record->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection($collection);
            $oldPath = $old->getPathRelativeToRoot();
            $before = Storage::disk('public')->allFiles();
            $this->mock(FileManipulator::class)->shouldReceive('createDerivedFiles')->once()->andThrow(new RuntimeException('Disk failure'));
            try {
                app($service)->update($record, ['name_en' => 'Changed', 'remove_'.$collection => true], UploadedFile::fake()->image('new.jpg'));
                $this->fail('Expected upload failure');
            } catch (RuntimeException $exception) {
                $this->assertSame('Disk failure', $exception->getMessage());
            }
            $this->assertSame('Original', $record->fresh()->name_en);
            $this->assertDatabaseHas('media', ['id' => $old->id]);
            Storage::disk('public')->assertExists($oldPath);
            $this->assertSame($before, Storage::disk('public')->allFiles());
        }
    }

    public function test_outer_rollback_restores_a_replaced_logo_without_orphan_files(): void
    {
        $brand = Brand::factory()->create();
        $old = $brand->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('logo');
        $before = Storage::disk('public')->allFiles();
        try {
            DB::transaction(function () use ($brand): void {
                app(BrandService::class)->update($brand, [], UploadedFile::fake()->image('new.jpg'));
                throw new RuntimeException('Outer rollback');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseHas('media', ['id' => $old->id]);
        }
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('media', 1);
    }

    public function test_successful_replacement_removes_the_old_file_after_commit(): void
    {
        $brand = Brand::factory()->create();
        $old = $brand->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('logo');
        $updated = app(BrandService::class)->update($brand, [], UploadedFile::fake()->image('new.jpg'));
        Storage::disk('public')->assertMissing($old->getPathRelativeToRoot());
        $this->assertDatabaseMissing('media', ['id' => $old->id]);
        $new = $updated->fresh()->getFirstMedia('logo');
        Storage::disk('public')->assertExists($new->getPathRelativeToRoot());
    }

    public function test_deleting_image_inside_rolled_back_transaction_keeps_its_file(): void
    {
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('images');
        try {
            DB::transaction(function () use ($product, $media): void {
                app(ProductMediaService::class)->delete($product, $media);
                throw new RuntimeException('Rollback delete');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseHas('media', ['id' => $media->id]);
        }
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_legacy_media_paths_and_conversions_remain_available(): void
    {
        $media = new Media(['file_name' => 'legacy.jpg', 'disk' => 'public', 'uuid' => 'a9bad36d-413e-466a-8ced-686f6cff8f1d', 'custom_properties' => []]);
        $media->id = 123;
        Storage::disk('public')->put('123/legacy.jpg', 'legacy');
        $paths = app(UuidPathGenerator::class);
        $this->assertSame('123/', $paths->getPath($media));
        $this->assertSame('123/conversions/', $paths->getPathForConversions($media));
        $media->uuid = null;
        $this->assertSame('123/', $paths->getPath($media));
    }

    public function test_deleting_legacy_media_removes_its_conversions_without_touching_other_files(): void
    {
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('legacy.jpg'))->toMediaCollection('images');
        foreach (Storage::disk('public')->allFiles($media->uuid) as $file) {
            Storage::disk('public')->move($file, $media->id.substr($file, strlen($media->uuid)));
        }
        DB::table('media')->where('id', $media->id)->update(['custom_properties' => '{}']);
        Storage::disk('public')->put('unrelated/keep.txt', 'keep');
        app(ProductMediaService::class)->delete($product, $media->fresh());
        $this->assertSame(['unrelated/keep.txt'], Storage::disk('public')->allFiles());
    }

    public function test_nested_color_details_require_the_english_name_on_the_same_item(): void
    {
        $this->postJson('/api/v1/admin/products', [
            'category_id' => Category::factory()->create()->id, 'name_ar' => 'حقيبة', 'name_en' => 'Color bag', 'sales_channel' => 'retail',
            'variants' => [['color_name_en' => 'Red'], ['color_hex' => '#000000']],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.1.color_name_en');
    }

    public function test_oversized_variant_array_is_rejected_before_per_item_database_queries(): void
    {
        $category = Category::factory()->create();
        $variants = array_map(fn ($index) => ['sku' => 'SKU-'.$index, 'barcode' => 'EXT-'.$index], range(1, 101));
        DB::enableQueryLog();
        try {
            $this->postJson('/api/v1/admin/products', [
                'category_id' => $category->id, 'name_ar' => 'حقيبة', 'name_en' => 'Large batch',
                'sales_channel' => 'retail', 'variants' => $variants,
            ])->assertUnprocessable()->assertJsonValidationErrors('variants');
            $variantQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'product_variants'));
            $this->assertCount(0, $variantQueries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_partial_color_update_uses_existing_name_but_cannot_clear_it(): void
    {
        $variant = ProductVariant::factory()->create(['color_name_en' => 'Black', 'color_name_ar' => 'أسود', 'color_hex' => '#000000']);
        $url = "/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}";
        $this->patchJson($url, ['color_hex' => '#111111'])->assertOk();
        $this->patchJson($url, ['color_name_en' => '0'])->assertOk();
        $this->patchJson($url, ['color_hex' => '#222222'])->assertOk();
        $this->patchJson($url, ['color_name_en' => null])->assertUnprocessable()->assertJsonValidationErrors('color_name_en');
    }

    public function test_stale_variant_instance_does_not_reintroduce_duplicate_combinations(): void
    {
        $product = Product::factory()->create();
        $stale = ProductVariant::factory()->create(['product_id' => $product->id, 'color_name_en' => 'Red', 'size' => 'S', 'capacity' => null]);
        $product->variants()->whereKey($stale->id)->update(['color_name_en' => 'Blue']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'color_name_en' => 'Blue', 'size' => 'M', 'capacity' => null]);
        $this->expectException(BusinessException::class);
        app(VariantService::class)->update($stale, ['size' => 'M']);
    }

    public function test_parent_array_and_invalid_tree_filters_are_validation_errors(): void
    {
        foreach (['parent_id[]=1', 'tree[]=1', 'tree=banana', 'tree=1 & search[]=x', 'parent_id=999999999999999999999999999'] as $query) {
            $this->getJson('/api/v1/admin/categories?'.$query)->assertUnprocessable();
        }
    }

    public function test_zero_is_a_literal_search_term_in_all_catalog_lists(): void
    {
        $category = Category::factory()->create(['name_ar' => 'دعم', 'name_en' => 'Support']);
        $brand = Brand::factory()->create(['name_ar' => 'دعم', 'name_en' => 'Support']);
        $zeroCategory = Category::factory()->create(['name_ar' => 'صفر', 'name_en' => 'Category 0']);
        $zeroBrand = Brand::factory()->create(['name_ar' => 'صفر', 'name_en' => 'Brand 0']);
        $zeroProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id, 'name_ar' => 'صفر', 'name_en' => 'Product 0']);
        Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id, 'name_ar' => 'واحد', 'name_en' => 'Product 1']);
        foreach (['categories' => $zeroCategory->id, 'brands' => $zeroBrand->id, 'products' => $zeroProduct->id] as $resource => $id) {
            $this->getJson('/api/v1/admin/'.$resource.'?search=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        }
    }

    public function test_out_of_range_route_ids_return_404(): void
    {
        foreach (['products', 'categories', 'brands'] as $resource) {
            $this->getJson('/api/v1/admin/'.$resource.'/999999999999999999999999999')->assertNotFound();
        }
    }

    public function test_deleted_variant_is_rejected_by_media_validation(): void
    {
        $variant = ProductVariant::factory()->create();
        $variant->delete();
        $this->post("/api/v1/admin/products/{$variant->product_id}/media", [
            'collection' => 'images', 'variant_id' => $variant->id, 'files' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
    }

    public function test_creation_cannot_reference_a_category_deleted_after_validation(): void
    {
        $category = Category::factory()->create();
        $category->delete();
        $this->expectException(BusinessException::class);
        app(ProductService::class)->create(['category_id' => $category->id, 'name_ar' => 'حقيبة', 'name_en' => 'Deleted category', 'sales_channel' => 'retail'], User::factory()->create());
    }

    public function test_null_active_flag_does_not_deactivate_or_reject_a_published_category(): void
    {
        $category = Category::factory()->create();
        Product::factory()->published()->create(['category_id' => $category->id]);
        $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => null])->assertOk();
        $this->assertTrue($category->fresh()->is_active);
    }
}
