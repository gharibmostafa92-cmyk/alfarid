<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\ProductMediaService;
use App\Services\InventoryService;
use App\Services\ProductionReceiptService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogExecutionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_image_retry_replays_existing_media_and_does_not_write_files(): void
    {
        $product = Product::factory()->create();
        $service = app(ProductMediaService::class);
        $data = ['collection' => 'images', 'client_upload_ids' => ['c24df583-439e-4619-a579-7efc766cd890']];
        $first = $service->upload($product, $data, [UploadedFile::fake()->image('first.jpg')])->first();
        $paths = Storage::disk('r2_public')->allFiles();
        $replay = $service->upload($product, $data, [UploadedFile::fake()->image('retry.jpg')])->first();
        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('media', 1);
        $this->assertEqualsCanonicalizing($paths, Storage::disk('r2_public')->allFiles());
    }

    public function test_catalog_list_uses_first_image_even_if_video_precedes_it_and_omits_gallery(): void
    {
        $this->signIn(['products.view']);
        $product = Product::factory()->create();
        $video = $product->addMedia(UploadedFile::fake()->image('placeholder.jpg'))->toMediaCollection('images');
        $video->update(['collection_name' => 'video']);
        $image = $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection('images');
        $product->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection('images');
        $this->getJson('/api/v1/admin/products')->assertOk()
            ->assertJsonPath('data.0.main_image', $image->getUrl('thumb'))
            ->assertJsonMissingPath('data.0.images')->assertJsonMissingPath('data.0.video');
        $this->getJson('/api/v1/admin/products/'.$product->id)->assertOk()->assertJsonCount(2, 'data.images');
    }

    public function test_published_product_deletion_requires_publish_permission(): void
    {
        $this->signIn(['products.delete']);
        $product = Product::factory()->create(['status' => ProductStatus::Published]);
        $this->deleteJson('/api/v1/admin/products/'.$product->id)->assertForbidden();
        $this->assertNotSoftDeleted($product);
    }

    public function test_receipt_preserves_four_decimal_cost_and_rounds_weighted_average(): void
    {
        $this->seed(SettingSeeder::class);
        $staff = User::factory()->create();
        $branch = Branch::factory()->create();
        $staff->update(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create(['cost_price' => '1.0001']);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 1, 'created_by' => $staff->id,
        ]);
        app(ProductionReceiptService::class)->receive([
            'batch_number' => 'PRECISION', 'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '1.0002']],
        ], $staff->id, 'precision');
        $this->assertSame('1.0002', $variant->fresh()->cost_price);
        $this->assertSame('1.0002', $variant->fresh()->last_cost_price);
    }

    public function test_catalog_media_migrates_from_private_r2_to_public_bucket(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('images');
        $path = $media->getPathRelativeToRoot();
        foreach (Storage::disk('r2_public')->allFiles() as $file) {
            Storage::disk('r2')->put($file, Storage::disk('r2_public')->get($file));
            Storage::disk('r2_public')->delete($file);
        }
        $media->update(['disk' => 'r2', 'conversions_disk' => 'r2']);
        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();
        $this->assertSame('r2_public', $media->fresh()->disk);
        Storage::disk('r2_public')->assertExists($path);
        Storage::disk('r2')->assertMissing($path);
    }

    public function test_missing_upload_does_not_prevent_later_files_from_migrating(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('missing.jpg'))->toMediaCollection('images');
        $media->update(['disk' => 'public', 'conversions_disk' => 'public']);
        Storage::disk('local')->put('customer_documents/1/id.pdf', 'private document');
        $this->artisan('uploads:migrate-to-r2')->assertFailed();
        Storage::disk('r2')->assertExists('customer_documents/1/id.pdf');
        Storage::disk('r2_public')->assertMissing('customer_documents/1/id.pdf');
        $this->assertSame('public', $media->fresh()->disk);
    }

    public function test_upload_ids_must_match_file_count(): void
    {
        $this->signIn(['products.update']);
        $product = Product::factory()->create();
        $this->postJson('/api/v1/admin/products/'.$product->id.'/media', [
            'collection' => 'images', 'files' => [UploadedFile::fake()->image('photo.jpg')],
            'client_upload_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('client_upload_ids');
    }

    public function test_private_media_stays_signed_even_when_a_public_url_is_configured(): void
    {
        config(['filesystems.disks.r2.url' => 'https://incorrect-public.example.test']);
        $media = Product::factory()->create()->addMedia(UploadedFile::fake()->image('document.jpg'))
            ->toMediaCollection('documents', 'r2');
        $this->assertStringContainsString('?signed=1', $media->getUrl());
        $this->assertStringNotContainsString('incorrect-public', $media->getUrl());
    }

    public function test_public_media_rejects_the_private_bucket_configuration(): void
    {
        config(['filesystems.disks.r2.bucket' => 'private', 'filesystems.disks.r2_public.bucket' => 'private']);
        $this->expectException(\LogicException::class);
        Category::factory()->create()->addMedia(UploadedFile::fake()->image('category.jpg'))->toMediaCollection('image');
    }

    private function signIn(array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->withToken($user->createToken('execution-review')->plainTextToken);
    }
}
