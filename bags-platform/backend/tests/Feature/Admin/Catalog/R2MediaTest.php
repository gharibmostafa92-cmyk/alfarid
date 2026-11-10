<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use App\Services\Media\UuidPathGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class R2MediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_explicit_private_catalog_mode_uses_signed_urls_without_a_public_bucket(): void
    {
        config(['filesystems.catalog_disk' => 'r2', 'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret', 'filesystems.disks.r2.bucket' => 'private-catalog',
            'filesystems.disks.r2.endpoint' => 'https://r2.example.test', 'filesystems.disks.r2.root' => 'alfarid',
            'filesystems.disks.r2_public.bucket' => null, 'filesystems.disks.r2_public.url' => null,
            'services.gemini.api_key' => 'test-gemini', 'services.gemini.model' => 'test-model']);
        $category = Category::factory()->create();
        $media = $category->addMedia(UploadedFile::fake()->image('private-catalog.png'))->toMediaCollection('image');
        $this->assertSame('r2', $media->disk);
        $this->assertStringContainsString('?signed=1', $media->getUrl());
        $this->artisan('integrations:check')->assertSuccessful();
    }

    public function test_catalog_only_migration_uses_the_selected_r2_disk_and_preserves_local_sources(): void
    {
        Storage::fake('public');
        config(['filesystems.catalog_disk' => 'public']);
        $category = Category::factory()->create();
        $media = $category->addMedia(UploadedFile::fake()->image('existing-catalog.png'))->toMediaCollection('image');
        $path = $media->getPathRelativeToRoot();
        $original = Storage::disk('public')->get($path);
        config(['filesystems.catalog_disk' => 'r2']);
        $this->artisan('uploads:migrate-to-r2', ['--catalog-only' => true])->assertSuccessful();
        $this->assertSame('r2', $media->fresh()->disk);
        $this->assertSame($original, Storage::disk('r2')->get($path));
        Storage::disk('public')->assertExists($path);
    }

    public function test_r2_images_receive_private_signed_urls_and_use_the_configured_media_disk(): void
    {
        $disk = Storage::fake('r2');
        $disk->buildTemporaryUrlsUsing(fn ($path, $expiration, $options) => 'https://storage.example.test/'.rawurlencode($path).'?signed=1');
        config(['media-library.disk_name' => 'r2', 'filesystems.disks.r2.url' => null]);
        $category = Category::factory()->create();
        // Catalog collections live on the public bucket with stable public
        // URLs; private documents stay on `r2` with signed URLs.
        $public = Storage::fake('r2_public');
        $media = $category->addMedia(UploadedFile::fake()->image('category.png'))->toMediaCollection('image');
        $this->assertSame('r2_public', $media->disk);
        $this->assertSame('r2_public', $media->conversions_disk);
        $public->assertExists($media->getPathRelativeToRoot());
        $this->assertStringNotContainsString('?signed=1', $category->getFirstMediaUrl('image'));
    }

    public function test_explicit_local_media_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Uploaded media must be stored on R2.');
        $category->addMedia(UploadedFile::fake()->image('existing.png'))->toMediaCollection('image', 'public');
    }

    public function test_integration_status_does_not_print_credentials(): void
    {
        config(['filesystems.disks.r2.key' => 'secret-access-key', 'filesystems.disks.r2.secret' => 'secret-value',
            'filesystems.disks.r2.bucket' => 'test-bucket', 'filesystems.disks.r2.endpoint' => 'https://r2.example.test',
            'filesystems.disks.r2_public.bucket' => 'catalog-bucket', 'filesystems.disks.r2_public.url' => 'https://catalog.example.test/alfarid',
            'filesystems.disks.r2.root' => 'alfarid', 'services.gemini.api_key' => 'secret-gemini-key', 'services.gemini.model' => 'test-model']);
        $this->artisan('integrations:check')->expectsOutput('R2 and Gemini are configured. Use --live to verify the services.')->assertSuccessful();
    }

    public function test_private_signed_url_ttl_cannot_exceed_one_hour(): void
    {
        $captured = null;
        config([
            'filesystems.catalog_disk' => 'r2',
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret',
            'filesystems.disks.r2.bucket' => 'private-catalog',
            'filesystems.disks.r2.endpoint' => 'https://r2.example.test',
            'filesystems.disks.r2.download_url_ttl_seconds' => 999999,
            'filesystems.disks.r2_public.bucket' => 'other-bucket',
        ]);
        Storage::disk('r2')->buildTemporaryUrlsUsing(function (string $path, $expiration) use (&$captured): string {
            $captured = $expiration;

            return 'https://storage.example.test/'.rawurlencode($path).'?signed=1';
        });
        $media = Category::factory()->create()->addMedia(UploadedFile::fake()->image('private.png'))->toMediaCollection('image');
        $media->getUrl();

        $this->assertNotNull($captured);
        $this->assertTrue($captured->lessThanOrEqualTo(now()->addHour()->addSeconds(5)));
        $this->assertTrue($captured->greaterThan(now()->addMinutes(50)));
    }

    public function test_non_empty_media_prefix_is_rejected(): void
    {
        config(['media-library.prefix' => 'tenant']);
        $this->expectException(\LogicException::class);
        app(UuidPathGenerator::class)->getPath(new Media);
    }

    public function test_staging_cannot_store_catalog_media_on_a_local_disk(): void
    {
        $this->app['env'] = 'staging';
        config(['filesystems.catalog_disk' => 'public']);
        Storage::fake('public');
        $this->expectException(\LogicException::class);
        Category::factory()->create()->addMedia(UploadedFile::fake()->image('staging.png'))->toMediaCollection('image');
    }
}
