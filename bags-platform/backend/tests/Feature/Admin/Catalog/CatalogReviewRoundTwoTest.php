<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\BarcodeGenerator;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantService;
use App\Services\Media\UuidPathGenerator;
use App\Services\SettingService;
use App\Services\SlugService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogReviewRoundTwoTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->signIn(['categories.view', 'categories.create', 'categories.update', 'categories.delete', 'brands.create', 'brands.update', 'brands.delete', 'products.view', 'products.create', 'products.update', 'products.publish', 'products.view_cost']);
    }

    private function signIn(array $permissions): void
    {
        Auth::forgetGuards();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);
        $this->withToken($staff->createToken('round-two')->plainTextToken);
    }

    private function completeProduct(array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, 'wholesale_price' => 80]);
        $product->addMedia(UploadedFile::fake()->image('product.jpg'))->toMediaCollection('images');

        return $product;
    }

    public function test_reorder_requires_explicit_parent_and_keeps_the_existing_tree_on_failure(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $this->putJson('/api/v1/admin/categories/reorder', ['items' => [['id' => $child->id, 'sort_order' => 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.parent_id');
        $this->assertSame($parent->id, $child->fresh()->parent_id);
        $this->expectException(BusinessException::class);
        app(CategoryService::class)->reorder([['id' => $child->id, 'sort_order' => 4]]);
    }

    public function test_valid_reparent_batch_is_independent_of_item_order(): void
    {
        foreach ([false, true] as $reverse) {
            $root = Category::factory()->create();
            $child = Category::factory()->create(['parent_id' => $root->id]);
            $items = [['id' => $root->id, 'parent_id' => $child->id, 'sort_order' => 0], ['id' => $child->id, 'parent_id' => null, 'sort_order' => 0]];
            $this->putJson('/api/v1/admin/categories/reorder', ['items' => $reverse ? array_reverse($items) : $items])->assertOk();
            $this->assertSame($child->id, $root->fresh()->parent_id);
            $this->assertNull($child->fresh()->parent_id);
        }
    }

    public function test_editor_cannot_unpublish_or_archive_a_published_product(): void
    {
        $product = $this->completeProduct(['status' => ProductStatus::Published]);
        $this->signIn(['products.update']);
        foreach (['draft', 'archived'] as $status) {
            $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => $status, 'name_en' => 'Should rollback'])->assertForbidden();
            $this->assertSame(ProductStatus::Published, $product->fresh()->status);
            $this->assertNotSame('Should rollback', $product->fresh()->name_en);
        }
    }

    public function test_publisher_can_unpublish_and_editor_can_archive_a_draft(): void
    {
        $product = $this->completeProduct(['status' => ProductStatus::Published]);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'draft'])->assertOk();
        $this->signIn(['products.update']);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'archived'])->assertOk();
    }

    public function test_publishing_under_an_inactive_ancestor_is_rejected(): void
    {
        $parent = Category::factory()->create(['is_active' => false]);
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = $this->completeProduct(['category_id' => $child->id]);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_update_and_reorder_cannot_move_published_subtree_under_inactive_ancestors(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $this->completeProduct(['category_id' => $child->id, 'status' => ProductStatus::Published]);
        $inactive = Category::factory()->create(['is_active' => false]);
        $this->patchJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $inactive->id])->assertUnprocessable();
        $this->putJson('/api/v1/admin/categories/reorder', ['items' => [['id' => $root->id, 'parent_id' => $inactive->id, 'sort_order' => 0]]])->assertUnprocessable();
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_metadata_updates_with_unchanged_parent_do_not_take_the_exclusive_tree_lock(): void
    {
        $parent = Category::factory()->create();
        $category = Category::factory()->create(['parent_id' => $parent->id]);
        config(['database.connections.lock_probe' => config('database.connections.pgsql')]);
        $probe = DB::connection('lock_probe');
        Event::listen('eloquent.updating: '.Category::class, function (Category $updating) use ($category, $probe): void {
            if ($updating->id !== $category->id) {
                return;
            }
            $probe->beginTransaction();
            try {
                $locked = $probe->selectOne('SELECT pg_try_advisory_xact_lock_shared(?) AS acquired', [2026101501]);
                $this->assertTrue($locked->acquired);
            } finally {
                $probe->rollBack();
            }
        });
        app(CategoryService::class)->update($category, ['description_en' => 'Updated', 'parent_id' => (string) $parent->id, 'is_active' => true], null);
        $this->assertSame('Updated', $category->fresh()->description_en);
        DB::disconnect('lock_probe');
    }

    public function test_sibling_reorder_loads_categories_once(): void
    {
        $categories = Category::factory()->count(30)->create();
        $items = $categories->map(fn ($category, $index) => ['id' => $category->id, 'parent_id' => null, 'sort_order' => $index])->all();
        DB::enableQueryLog();
        try {
            app(CategoryService::class)->reorder($items);
            $selects = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], '"categories"'));
            $this->assertCount(1, $selects);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_creation_accepts_only_draft_and_reports_validation_error_early(): void
    {
        $this->postJson('/api/v1/admin/products', ['category_id' => Category::factory()->create()->id, 'name_ar' => 'حقيبة', 'name_en' => 'Draft first', 'sales_channel' => 'retail', 'status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_body_reference_race_returns_422_without_model_class_names(): void
    {
        $brand = Brand::factory()->create();
        $brand->delete();
        try {
            app(ProductService::class)->create(['category_id' => Category::factory()->create()->id, 'brand_id' => $brand->id, 'name_ar' => 'حقيبة', 'name_en' => 'Stale brand', 'sales_channel' => 'retail'], User::factory()->create());
            $this->fail('Expected a reference validation failure');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('brand_id', $exception->errors);
        }
        config(['app.debug' => false]);
        $response = $this->getJson('/api/v1/admin/products/999999')->assertNotFound();
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_clearing_barcode_regenerates_the_internal_code(): void
    {
        $variant = ProductVariant::factory()->create(['barcode' => 'EXT-123']);
        $this->patchJson("/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}", ['barcode' => null])
            ->assertOk()->assertJsonPath('data.barcode', BarcodeGenerator::fromId($variant->id));
    }

    public function test_prices_with_more_than_two_decimal_places_are_rejected_in_both_write_paths(): void
    {
        $product = Product::factory()->create();
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['retail_price' => '10.999', 'wholesale_price' => '8.999', 'cost_price' => '5.999'])
            ->assertUnprocessable()->assertJsonValidationErrors(['retail_price', 'wholesale_price', 'cost_price']);
        $this->postJson('/api/v1/admin/products', ['category_id' => $product->category_id, 'name_ar' => 'حقيبة', 'name_en' => 'Nested decimals', 'sales_channel' => 'retail', 'variants' => [['retail_price' => '10.999']]])
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.retail_price');
    }

    public function test_slug_and_sku_normalization_and_legacy_case_uniqueness(): void
    {
        $category = $this->postJson('/api/v1/admin/categories', ['name_ar' => 'قسم', 'name_en' => 'Normalized', 'slug' => 'Mixed-Slug'])->assertCreated()->assertJsonPath('data.slug', 'mixed-slug');
        Category::factory()->create(['slug' => 'Legacy-Slug']);
        $this->postJson('/api/v1/admin/categories', ['name_ar' => 'قسم', 'name_en' => 'Duplicate', 'slug' => 'LEGACY-SLUG'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $product = Product::factory()->create(['category_id' => $category->json('data.id')]);
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'mixed-sku'])->assertCreated()->assertJsonPath('data.sku', 'MIXED-SKU');
        ProductVariant::factory()->create(['sku' => 'legacy-SKU']);
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'LEGACY-sku', 'size' => 'L'])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_unicode_identifiers_are_rejected(): void
    {
        $this->postJson('/api/v1/admin/brands', ['name_ar' => 'براند', 'name_en' => 'Unicode', 'slug' => 'براند'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $product = Product::factory()->create();
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'متغير'])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_automatic_slug_only_reserves_numeric_suffixes_including_legacy_case(): void
    {
        Category::factory()->create(['slug' => 'Leather']);
        Category::factory()->create(['slug' => 'leather-2']);
        Category::factory()->create(['slug' => 'leather-handbag']);
        $this->assertSame('leather-3', SlugService::make(Category::class, 'Leather'));
    }

    public function test_soft_deleted_products_still_prevent_category_and_brand_deletion(): void
    {
        $product = Product::factory()->create();
        $product->delete();
        $this->deleteJson("/api/v1/admin/categories/{$product->category_id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/brands/{$product->brand_id}")->assertUnprocessable();
    }

    public function test_deleted_variant_media_is_hidden_and_cannot_be_reordered_or_deleted(): void
    {
        $variant = ProductVariant::factory()->create();
        $media = $variant->addMedia(UploadedFile::fake()->image('variant.jpg'))->toMediaCollection('images');
        $variant->delete();
        $url = "/api/v1/admin/products/{$variant->product_id}/media";
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($url.'/reorder', ['ids' => [$media->id]])->assertNotFound();
        $this->deleteJson($url.'/'.$media->id)->assertNotFound();
        Storage::disk('r2')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_media_layout_backfill_is_idempotent_and_urls_do_not_probe_storage_afterwards(): void
    {
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('legacy.jpg'))->toMediaCollection('images');
        foreach (Storage::disk('r2')->allFiles($media->uuid) as $file) {
            Storage::disk('r2')->move($file, $media->id.substr($file, strlen($media->uuid)));
        }
        DB::table('media')->where('id', $media->id)->update(['custom_properties' => '{}']);
        $this->artisan('media:backfill-path-layouts')->expectsOutput('Updated 1 media layouts.')->assertSuccessful();
        $this->artisan('media:backfill-path-layouts')->expectsOutput('Updated 0 media layouts.')->assertSuccessful();
        $fresh = $media->fresh();
        $this->assertSame('id', $fresh->getCustomProperty('path_layout'));
        Storage::shouldReceive('disk')->never();
        $paths = app(UuidPathGenerator::class);
        $this->assertSame($media->id.'/', $paths->getPath($fresh));
        $this->assertSame($media->id.'/conversions/', $paths->getPathForConversions($fresh));
    }

    public function test_api_batch_upload_rolls_back_the_failing_item_and_earlier_images(): void
    {
        $product = Product::factory()->create();
        $real = app(FileManipulator::class);
        $count = 0;
        $this->mock(FileManipulator::class)->shouldReceive('createDerivedFiles')->twice()->andReturnUsing(function (Media $media) use ($real, &$count): void {
            $real->createDerivedFiles($media);
            if (++$count === 2) {
                throw new \RuntimeException('Conversion failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post("/api/v1/admin/products/{$product->id}/media", ['collection' => 'images', 'files' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]]);
            $this->fail('Expected upload failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Conversion failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_setting_service_rejects_invalid_counter_values_and_rolls_back_other_updates(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $originalName = $service->get('company_name');
        foreach (['abc', '12.5', '1e3', null, true, 0, -1] as $invalid) {
            try {
                $service->updateBatch(['company_name' => 'Should rollback', 'order_next_number' => $invalid]);
                $this->fail('Invalid counter was accepted');
            } catch (BusinessException) {
                $this->assertSame($originalName, $service->get('company_name'));
                $this->assertDatabaseHas('settings', ['key' => 'order_next_number', 'value' => '1000']);
            }
        }
    }

    public function test_number_generation_refuses_corrupt_stored_counter(): void
    {
        $this->seed(SettingSeeder::class);
        // منذ H2 تُرقَّم الطلبات بتسلسل PostgreSQL؛ العداد الصفّي ما زال
        // حاكمًا للفواتير (تسلسل بلا فجوات قانونًا) فالحارس يُختبَر عليها
        Setting::where('key', 'invoice_next_number')->update(['value' => 'abc']);
        $this->expectException(BusinessException::class);
        app(SettingService::class)->generateNextNumber('invoice');
    }

    public function test_variant_update_eager_loads_product_for_weight_fallback(): void
    {
        $product = Product::factory()->create(['weight_kg' => 2.5]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'weight_kg' => null]);
        $updated = app(VariantService::class)->update($variant, ['retail_price' => 200]);
        $this->assertTrue($updated->relationLoaded('product'));
        $this->assertSame('2.500', $updated->product->weight_kg);
    }
}
