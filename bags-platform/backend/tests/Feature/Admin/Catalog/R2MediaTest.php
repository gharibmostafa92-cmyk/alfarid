<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class R2MediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_r2_images_receive_private_signed_urls_and_use_the_configured_media_disk(): void
    {
        $disk = Storage::fake('r2');
        $disk->buildTemporaryUrlsUsing(fn ($path, $expiration, $options) => 'https://storage.example.test/'.rawurlencode($path).'?signed=1');
        config(['media-library.disk_name' => 'r2', 'filesystems.disks.r2.url' => null]);
        $category = Category::factory()->create();
        $media = $category->addMedia(UploadedFile::fake()->image('category.png'))->toMediaCollection('image');
        $this->assertSame('r2', $media->disk);
        $disk->assertExists($media->getPathRelativeToRoot());
        $this->assertStringContainsString('?signed=1', $category->getFirstMediaUrl('image'));
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
            'filesystems.disks.r2.root' => 'alfarid', 'services.gemini.api_key' => 'secret-gemini-key', 'services.gemini.model' => 'test-model']);
        $this->artisan('integrations:check')->expectsOutput('R2 and Gemini are configured. Use --live to verify the services.')->assertSuccessful();
    }
}
