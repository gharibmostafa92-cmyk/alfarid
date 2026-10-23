<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Media\R2UploadMigration;
use App\Services\PrivateAttachments;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class R2UploadMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_originals_and_derived_files_move_together_and_keep_their_paths(): void
    {
        $source = Storage::fake('public');
        $private = Storage::fake('local');
        $public = Storage::fake('r2_public');
        $media = Category::factory()->create()->addMedia(UploadedFile::fake()->image('old.png'))->toMediaCollection('image');
        $path = $media->getPathRelativeToRoot();
        $source->put($path, Storage::disk('r2_public')->get($path));
        // NB: keep clear of real generated names (old-thumb/medium.webp), which
        // already exist on the target from addMedia above.
        $conversion = dirname($path).'/conversions/old-custom.webp';
        $private->put($conversion, 'converted-image');
        Storage::disk('r2_public')->delete($path);
        DB::table('media')->where('id', $media->id)->update(['disk' => 'public', 'conversions_disk' => 'local']);

        $this->artisan('uploads:migrate-to-r2')->assertSuccessful();

        // H5: catalog media migrate to the public bucket with stable public URLs.
        $this->assertSame('r2_public', $media->fresh()->disk);
        $this->assertSame('r2_public', $media->fresh()->conversions_disk);
        $this->assertSame($path, $media->fresh()->getPathRelativeToRoot());
        $public->assertExists([$path, $conversion]);
        $source->assertExists($path);
        $this->assertStringNotContainsString('?signed=1', $media->fresh()->getUrl());
    }

    public function test_private_and_orphan_uploads_are_verified_then_removed_without_moving_certificates(): void
    {
        $local = Storage::fake('local');
        $public = Storage::fake('public');
        $local->put('customer_documents/42/identity.png', 'private-image');
        $local->put('certificates/trust.pem', 'application-certificate');
        $public->put('banners/banner.png', 'banner-image');

        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();

        $local->assertMissing('customer_documents/42/identity.png');
        $public->assertMissing('banners/banner.png');
        $local->assertExists('certificates/trust.pem');
        $this->assertSame('private-image', Storage::disk('r2')->get('customer_documents/42/identity.png'));
        $this->assertSame('banner-image', Storage::disk('r2')->get('banners/banner.png'));
        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();
    }

    public function test_mismatched_existing_cloud_objects_are_never_overwritten_or_deleted(): void
    {
        $local = Storage::fake('local');
        $local->put('return_requests/1/photo.png', 'original');
        Storage::disk('r2')->put('return_requests/1/photo.png', 'different');
        try {
            app(R2UploadMigration::class)->copy('local', 'return_requests/1/photo.png');
            $this->fail('A mismatched object must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('verification failed', $exception->getMessage());
        }
        $this->assertSame('original', $local->get('return_requests/1/photo.png'));
        $this->assertSame('different', Storage::disk('r2')->get('return_requests/1/photo.png'));
    }

    public function test_source_changes_prevent_deletion_after_upload(): void
    {
        $local = Storage::fake('local');
        $path = 'return_requests/1/photo.png';
        $local->put($path, 'original');
        $migration = app(R2UploadMigration::class);
        $migration->copy('local', $path);
        $local->put($path, 'changed');
        try {
            $migration->deleteVerifiedSource('local', $path);
            $this->fail('Changed files must be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Source changed', $exception->getMessage());
        }
        $local->assertExists($path);
    }

    public function test_r2_failure_never_falls_back_to_local_storage(): void
    {
        $local = Storage::fake('local');
        $public = Storage::fake('public');
        Storage::shouldReceive('disk')->with('r2')->andThrow(new RuntimeException('R2 unavailable'));
        try {
            (new PrivateAttachments)->store(UploadedFile::fake()->image('photo.png'), 'return_requests/1');
            $this->fail('Upload must fail when R2 is unavailable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('R2 unavailable', $exception->getMessage());
        }
        $this->assertSame([], $local->allFiles());
        $this->assertSame([], $public->allFiles());
    }
}
