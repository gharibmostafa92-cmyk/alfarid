<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\ProductService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogVerificationReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_color_only_gallery_can_be_published_and_appears_on_store_cards_and_variants(): void
    {
        $staff = $this->staff(['products.publish']);
        $product = Product::factory()->create(['sales_channel' => 'retail']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'retail_price' => 100]);
        $image = $variant->addMedia(UploadedFile::fake()->image('blue.jpg'))->toMediaCollection('images');
        $inactive = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);
        $inactive->addMedia(UploadedFile::fake()->image('hidden.jpg'))->toMediaCollection('images');

        app(ProductService::class)->changeStatus($product, ProductStatus::Published, $staff);
        $this->getJson('/api/v1/store/products/'.$product->slug)->assertOk()
            ->assertJsonCount(1, 'data.images')->assertJsonPath('data.images.0.id', $image->id)
            ->assertJsonCount(1, 'data.variants')->assertJsonPath('data.variants.0.images.0.id', $image->id);
        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonPath('data.0.image', $image->getUrl('thumb'));

        try {
            app(ProductMediaService::class)->delete($product->fresh(), $image);
            $this->fail('Deleting the only visible color image must be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame('لا يمكن حذف آخر صورة لمنتج منشور', $exception->getMessage());
        }
        $this->assertDatabaseHas('media', ['id' => $image->id]);
    }

    public function test_a_variant_image_allows_replacing_the_last_general_image(): void
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        $general = $product->addMedia(UploadedFile::fake()->image('general.jpg'))->toMediaCollection('images');
        $color = $variant->addMedia(UploadedFile::fake()->image('color.jpg'))->toMediaCollection('images');

        app(ProductMediaService::class)->delete($product, $general);
        $this->assertDatabaseMissing('media', ['id' => $general->id]);
        $this->assertSame([$color->id], $product->fresh()->catalogImages()->pluck('id')->all());
    }

    public function test_media_validation_rejects_disguised_extensions_and_more_than_sixteen_megapixels(): void
    {
        $this->staff(['products.update']);
        $product = Product::factory()->create();
        $file = UploadedFile::fake()->image('valid.png');
        $disguised = new UploadedFile($file->getPathname(), 'image.html', 'image/png', test: true);
        $url = '/api/v1/admin/products/'.$product->id.'/media';
        $this->postJson($url, ['collection' => 'images', 'files' => [$disguised]])
            ->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $large = UploadedFile::fake()->image('large.png', 1, 1);
        $bytes = file_get_contents($large->getPathname());
        file_put_contents($large->getPathname(), substr_replace($bytes, pack('NN', 5000, 4000), 16, 8));
        $this->postJson($url, ['collection' => 'images', 'files' => [$large]])
            ->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertDatabaseCount('media', 0);
    }

    public function test_create_key_rejects_non_uuid_values_before_creating_a_product(): void
    {
        $this->staff(['products.create']);
        $this->postJson('/api/v1/admin/products', [
            'category_id' => Category::factory()->create()->id, 'name_ar' => 'حقيبة',
            'name_en' => 'Bag', 'sales_channel' => 'retail', 'create_key' => 'arbitrary-text',
        ])->assertUnprocessable()->assertJsonValidationErrors('create_key');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_receipt_validation_batches_variant_checks_and_rejects_inactive_and_deleted_variants(): void
    {
        $this->staff(['inventory.adjust', 'products.view_cost']);
        $active = ProductVariant::factory()->create(['is_active' => true]);
        $inactive = ProductVariant::factory()->create(['is_active' => false]);
        $deleted = ProductVariant::factory()->create(['is_active' => true]);
        $deleted->delete();
        $branch = Branch::factory()->create(['is_active' => true]);

        DB::enableQueryLog();
        try {
            $this->withHeader('Idempotency-Key', 'bulk-check')->postJson('/api/v1/admin/production-receipts', [
                'batch_number' => 'BULK-CHECK', 'branch_id' => $branch->id,
                'items' => collect([$active, $inactive, $deleted])->map(fn ($variant) => [
                    'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '1.2345',
                ])->all(),
            ])->assertUnprocessable()->assertJsonValidationErrors(['items.1.product_variant_id', 'items.2.product_variant_id'])
                ->assertJsonMissingValidationErrors('items.0.product_variant_id');
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "product_variants"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertDatabaseCount('production_receipts', 0);
    }

    public function test_demo_seeder_refuses_production_before_creating_any_records(): void
    {
        $before = Category::count();
        $environment = app()->environment();
        app()->instance('env', 'production');
        try {
            app(CatalogSeeder::class)->run();
            $this->fail('Demo seeder must refuse production.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Catalog demo data cannot be seeded in production.', $exception->getMessage());
        } finally {
            app()->instance('env', $environment);
        }
        $this->assertSame($before, Category::count());
        $this->assertDatabaseMissing('users', ['email' => 'catalog-demo@alfarid.test']);
    }

    private function staff(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);
        $this->withToken($staff->createToken('verification-review')->plainTextToken);

        return $staff;
    }
}
