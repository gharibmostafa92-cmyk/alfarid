<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Services\Catalog\ProductMediaService;
use App\Support\CatalogMedia;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// task3 Slice B: M-1 morph-alias safety + HIGH-1 disk-routing guard.
// M-1: readers must use getMorphClass() so a future morph alias does not
// silently empty listings, break deletes, or misreport variant ownership.
class Task3MediaMorphDiskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_full_collection_replays_previous_uploads_but_rejects_new_images(): void
    {
        $product = Product::factory()->create();
        $service = app(ProductMediaService::class);
        $uploadId = (string) Str::uuid();
        $data = ['collection' => 'images', 'client_upload_ids' => [$uploadId]];
        $image = UploadedFile::fake()->image('first.jpg');
        $first = $service->upload($product, $data, [$image])->sole();

        $attributes = array_diff_key($first->getAttributes(), array_flip(['id', 'uuid', 'custom_properties']));
        $rows = [];
        for ($i = 2; $i <= 199; $i++) {
            $rows[] = array_merge($attributes, ['uuid' => (string) Str::uuid(), 'custom_properties' => '{}']);
        }
        DB::table('media')->insert($rows);

        try {
            $service->upload($product, ['collection' => 'images'], [
                UploadedFile::fake()->image('extra-a.jpg'),
                UploadedFile::fake()->image('extra-b.jpg'),
            ]);
            $this->fail('An oversized batch must be rejected before its first upload.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertCount(199, $service->index($product));
        $service->upload($product, ['collection' => 'images'], [UploadedFile::fake()->image('last.jpg')]);

        $this->assertSame($first->id, $service->upload($product, $data, [$image])->sole()->id);

        try {
            $service->upload($product, ['collection' => 'images'], [UploadedFile::fake()->image('extra.jpg')]);
            $this->fail('A collection at the reorder limit must reject new images.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertCount(200, $service->index($product));
        $this->assertDatabaseMissing('media', ['collection_name' => 'images_staging']);
    }

    public function test_media_listing_survives_a_product_morph_alias(): void
    {
        $this->withProductMorphAliases();

        try {
            $product = Product::factory()->create();
            app(ProductMediaService::class)->upload($product, ['collection' => 'images'], [
                UploadedFile::fake()->image('alias.jpg'),
            ]);

            $listed = app(ProductMediaService::class)->index($product);

            $this->assertCount(1, $listed);
            $this->assertSame('images', $listed->first()->collection_name);
        } finally {
            $this->restoreMorphMap();
        }
    }

    public function test_media_delete_survives_a_product_morph_alias(): void
    {
        $this->withProductMorphAliases();

        try {
            $product = Product::factory()->create();
            $created = app(ProductMediaService::class)->upload($product, ['collection' => 'images'], [
                UploadedFile::fake()->image('alias-delete.jpg'),
            ]);

            app(ProductMediaService::class)->delete($product, $created->first());

            $this->assertCount(0, app(ProductMediaService::class)->index($product));
        } finally {
            $this->restoreMorphMap();
        }
    }

    /** @var array<string, class-string> */
    private array $previousMorphMap = [];

    private function withProductMorphAliases(): void
    {
        // Relation::$morphMap is process-global: snapshot it so later tests
        // in the same process keep the application map (task21e caught this).
        $this->previousMorphMap = Relation::$morphMap;
        Relation::morphMap([
            'product' => Product::class,
            'product_variant' => \App\Models\ProductVariant::class,
        ], false);
    }

    private function restoreMorphMap(): void
    {
        Relation::$morphMap = $this->previousMorphMap;
    }

    public function test_uploaded_images_land_on_the_configured_catalog_disk(): void
    {
        $product = Product::factory()->create();
        $created = app(ProductMediaService::class)->upload($product, ['collection' => 'images'], [
            UploadedFile::fake()->image('disk.jpg'),
        ]);

        $media = $created->first();
        $this->assertSame(CatalogMedia::disk(), $media->disk);
        $this->assertSame(CatalogMedia::disk(), $media->conversions_disk);
    }
}
