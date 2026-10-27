<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\BrandService;
use App\Services\Catalog\CategoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
    }

    private function signIn(array $permissions): void
    {
        Auth::forgetGuards();
        $staff = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff->givePermissionTo($permissions);
        $this->withToken($staff->createToken('audit')->plainTextToken);
    }

    public function test_nested_cost_is_ignored_without_permission(): void
    {
        $this->signIn(['products.create']);
        $response = $this->postJson('/api/v1/admin/products', [
            'category_id' => Category::factory()->create()->id,
            'name_ar' => 'حقيبة', 'name_en' => 'Bag', 'sales_channel' => 'retail',
            'variants' => [['cost_price' => 999, 'retail_price' => 1200]],
        ])->assertCreated();
        $this->assertArrayNotHasKey('cost_price', $response->json('data.variants.0'));
        $this->assertDatabaseHas('product_variants', ['product_id' => $response->json('data.id'), 'cost_price' => 0]);
    }

    public function test_nested_variants_cannot_override_their_product_or_inject_columns(): void
    {
        $this->signIn(['products.create', 'products.view_cost']);
        $other = Product::factory()->create();
        $this->postJson('/api/v1/admin/products', [
            'category_id' => $other->category_id, 'name_ar' => 'حقيبة', 'name_en' => 'Bag',
            'sales_channel' => 'retail', 'variants' => [['product_id' => $other->id, 'id' => 800]],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.0');
        $this->assertSame(0, $other->variants()->count());
        $this->assertDatabaseCount('products', 1);
    }

    public function test_duplicate_nested_identifiers_are_validation_errors(): void
    {
        $this->signIn(['products.create']);
        $this->postJson('/api/v1/admin/products', [
            'category_id' => Category::factory()->create()->id,
            'name_ar' => 'حقيبة', 'name_en' => 'Bag', 'sales_channel' => 'retail',
            'variants' => [
                ['sku' => 'REPEATED', 'barcode' => '123', 'color_name_en' => 'Red'],
                ['sku' => 'REPEATED', 'barcode' => '123', 'color_name_en' => 'Blue'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku', 'variants.1.barcode']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_empty_product_slug_is_regenerated_on_update(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->create();
        $this->putJson("/api/v1/admin/products/{$product->id}", ['slug' => null, 'name_en' => 'New Bag', 'expected_version' => 0])
            ->assertOk()->assertJsonPath('data.slug', 'new-bag');
    }

    public function test_empty_variant_sku_returns_validation_error(): void
    {
        $this->signIn(['products.update']);
        $variant = ProductVariant::factory()->create();
        $this->putJson("/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}", ['sku' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertSame($variant->sku, $variant->fresh()->sku);
    }

    public function test_variant_cost_writes_require_permission(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->create();
        $created = $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['cost_price' => 500])
            ->assertCreated();
        $id = $created->json('data.id');
        $this->putJson("/api/v1/admin/products/{$product->id}/variants/{$id}", ['cost_price' => 700])
            ->assertOk();
        $this->assertDatabaseHas('product_variants', ['id' => $id, 'cost_price' => 0]);
    }

    public function test_create_with_invalid_media_keeps_row_and_reports_clearly(): void
    {
        // F2: network I/O runs outside the tree/row lock, so the data write is
        // no longer atomic with the upload — a failed upload keeps the saved
        // row (imageless) and reports a clear message instead of rolling back.
        foreach ([CategoryService::class => Category::class, BrandService::class => Brand::class] as $service => $model) {
            try {
                app($service)->create(['name_ar' => 'اختبار', 'name_en' => 'Rollback'], UploadedFile::fake()->create('bad.txt', 1, 'text/plain'));
                $this->fail('Invalid media must fail.');
            } catch (BusinessException $e) {
                $this->assertSame(422, $e->status);
                $field = $model === Category::class ? 'category_id' : 'brand_id';
                $this->assertSame([$model::where('name_en', 'Rollback')->firstOrFail()->id], $e->errors[$field]);
            }
            $record = $model::where('name_en', 'Rollback')->firstOrFail();
            $this->assertSame(0, $record->media()->count());
        }
    }

    public function test_update_with_invalid_media_keeps_data_changes_and_no_media(): void
    {
        // F2: same trade-off as create — the data change persists, the image
        // change is fully rolled back, and the caller gets a clear message.
        foreach ([CategoryService::class => Category::class, BrandService::class => Brand::class] as $service => $model) {
            $record = $model::factory()->create(['name_en' => 'Original']);
            try {
                app($service)->update($record, ['name_en' => 'Changed'], UploadedFile::fake()->create('bad.txt', 1, 'text/plain'));
                $this->fail('Invalid media must fail.');
            } catch (BusinessException $e) {
                $this->assertSame(422, $e->status);
            }
            $this->assertSame('Changed', $record->fresh()->name_en);
            $this->assertSame(0, $record->fresh()->media()->count());
        }
    }

    public function test_reordering_invalid_subtree_rolls_back_all_items(): void
    {
        $this->signIn(['categories.update']);
        $root = Category::factory()->create(['sort_order' => 0]);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $leaf = Category::factory()->create(['parent_id' => $child->id]);
        $this->putJson('/api/v1/admin/categories/reorder', ['items' => [
            ['id' => $root->id, 'parent_id' => null, 'sort_order' => 50],
            ['id' => $root->id, 'parent_id' => $leaf->id, 'sort_order' => 1],
        ]])->assertUnprocessable();
        $this->assertSame(0, $root->fresh()->sort_order);
        // A distinct second item makes this fail inside the service after the first update.
        $other = Category::factory()->create(['sort_order' => 0]);
        $this->putJson('/api/v1/admin/categories/reorder', ['items' => [
            ['id' => $other->id, 'parent_id' => null, 'sort_order' => 50],
            ['id' => $root->id, 'parent_id' => $leaf->id, 'sort_order' => 1],
        ]])->assertUnprocessable();
        $this->assertSame(0, $other->fresh()->sort_order);
    }

    public function test_moving_a_subtree_cannot_exceed_three_levels(): void
    {
        $this->signIn(['categories.update']);
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $otherRoot = Category::factory()->create();
        $otherChild = Category::factory()->create(['parent_id' => $otherRoot->id]);
        $this->putJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $otherChild->id])->assertUnprocessable();
        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    public function test_brand_supports_raster_logo_and_rejects_deletion_with_products(): void
    {
        $this->signIn(['brands.create', 'brands.delete']);
        $logo = UploadedFile::fake()->image('logo.png');
        $service = app(BrandService::class);
        $brand = $service->create(['name_ar' => 'براند', 'name_en' => 'Brand'], $logo);
        $this->assertNotEmpty($brand->getFirstMediaUrl('logo'));
        Product::factory()->create(['brand_id' => $brand->id]);
        $this->getJson('/api/v1/admin/brands')->assertStatus(410);
        $this->expectException(BusinessException::class);
        $service->delete($brand);
    }

    public function test_last_active_variant_of_published_product_is_protected(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $url = "/api/v1/admin/products/{$product->id}/variants/{$variant->id}";
        $this->putJson($url, ['is_active' => false])->assertUnprocessable();
        $this->deleteJson($url)->assertUnprocessable();
        $this->assertTrue($variant->fresh()->is_active);
        $this->assertFalse($variant->fresh()->trashed());
    }

    public function test_media_conversions_reorder_and_last_image_protection(): void
    {
        $this->signIn(['products.view', 'products.update']);
        $product = Product::factory()->create();
        $url = "/api/v1/admin/products/{$product->id}/media";
        $uploaded = $this->post($url, ['collection' => 'images', 'files' => [
            UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.jpg'),
        ]])->assertCreated();
        $first = $uploaded->json('data.0.id');
        $second = $uploaded->json('data.1.id');
        $this->assertNotEmpty($uploaded->json('data.0.thumb_url'));
        $this->assertNotEmpty($uploaded->json('data.0.medium_url'));
        $this->putJson($url.'/reorder', ['ids' => [$second, $first]])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $second);
        $product->update(['status' => ProductStatus::Published]);
        $this->deleteJson($url.'/'.$first)->assertOk();
        $this->deleteJson($url.'/'.$second)->assertUnprocessable();
    }

    public function test_reordering_foreign_media_is_rejected_without_changes(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $media = $other->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection('images');
        $this->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$media->id]])->assertNotFound();
        $this->assertSame($other->id, $media->fresh()->model_id);
    }

    public function test_variant_video_is_rejected(): void
    {
        $this->signIn(['products.update']);
        $variant = ProductVariant::factory()->create();
        $this->post("/api/v1/admin/products/{$variant->product_id}/media", [
            'collection' => 'video', 'variant_id' => $variant->id,
            'file' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('media', 0);
    }

    public function test_publishing_requires_permission_and_rolls_back_edits(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->create(['name_en' => 'Original']);
        $this->putJson("/api/v1/admin/products/{$product->id}", ['status' => 'published', 'name_en' => 'Changed', 'expected_version' => 0])
            ->assertForbidden();
        $this->assertSame('Original', $product->fresh()->name_en);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_product_delete_soft_deletes_all_variants(): void
    {
        $this->signIn(['products.delete']);
        $variant = ProductVariant::factory()->create();
        $this->deleteJson("/api/v1/admin/products/{$variant->product_id}")->assertOk();
        $this->assertSoftDeleted('products', ['id' => $variant->product_id]);
        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    public function test_non_positive_pagination_is_rejected_and_upper_limit_is_clamped(): void
    {
        $this->signIn(['categories.view', 'brands.view', 'products.view']);
        foreach (['categories', 'products'] as $resource) {
            $this->getJson("/api/v1/admin/{$resource}?per_page=-1")->assertUnprocessable()->assertJsonValidationErrors('per_page');
            $this->getJson("/api/v1/admin/{$resource}?per_page=101")->assertOk()->assertJsonPath('meta.per_page', 100);
        }
    }

    public function test_catalog_seed_matches_plan_and_is_repeatable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('categories', 7);
        $this->assertDatabaseCount('brands', 4);
        $this->assertDatabaseCount('products', 10);
        $this->assertSame(3, Category::where('slug', 'luggage')->firstOrFail()->children()->count());
        foreach (Product::with('variants')->get() as $product) {
            $this->assertSame(ProductStatus::Draft, $product->status);
            $this->assertContains($product->variants->count(), [2, 3]);
            $this->assertSame('P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT), $product->code);
            foreach ($product->variants as $variant) {
                $this->assertStringStartsWith($product->code.'-', $variant->sku);
                $this->assertMatchesRegularExpression('/^2[0-9]{12}$/', $variant->barcode);
            }
        }
    }
}
