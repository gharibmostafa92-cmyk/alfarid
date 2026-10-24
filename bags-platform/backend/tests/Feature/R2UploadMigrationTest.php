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
use PHPUnit\Framework\Attributes\TestWith;
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
        Storage::disk('r2')->assertMissing($path);
        $this->assertStringNotContainsString('?signed=1', $media->fresh()->getUrl());
    }

    #[TestWith(['public'])]
    #[TestWith(['r2_public'])]
    public function test_legacy_catalog_paths_are_rekeyed_and_copy_only_sources_can_be_cleaned_on_a_later_run(string $sourceDisk): void
    {
        Storage::fake('public');
        $source = Storage::disk($sourceDisk);
        Storage::fake('local');
        $media = Category::factory()->create()->addMedia(UploadedFile::fake()->image('legacy.png'))->toMediaCollection('image');
        $destination = $media->getPathRelativeToRoot();
        $legacy = $media->id.'/legacy.png';
        $source->put($legacy, Storage::disk('r2_public')->get($destination));
        $source->put($media->id.'/conversions/legacy-custom.webp', 'conversion');
        Storage::disk('r2_public')->deleteDirectory($media->uuid);
        DB::table('media')->where('id', $media->id)->update([
            'disk' => $sourceDisk, 'conversions_disk' => $sourceDisk, 'custom_properties' => json_encode(['path_layout' => 'id']),
        ]);

        $this->artisan('uploads:migrate-to-r2')->assertSuccessful();
        $this->assertSame($destination, $media->fresh()->getPathRelativeToRoot());
        Storage::disk('r2_public')->assertExists([$destination, $media->uuid.'/conversions/legacy-custom.webp']);
        Storage::disk('r2')->assertMissing($legacy);
        $source->assertExists($legacy);

        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();
        $source->assertMissing([$legacy, $media->id.'/conversions/legacy-custom.webp']);
        Storage::disk('r2_public')->assertExists($destination);
        $this->assertFalse($media->fresh()->hasCustomProperty('r2_migration_sources'));
        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();
    }

    public function test_cleanup_failure_after_metadata_save_can_be_retried(): void
    {
        $source = Storage::fake('public');
        Storage::fake('local');
        $media = Category::factory()->create()->addMedia(UploadedFile::fake()->image('retry.png'))->toMediaCollection('image');
        $path = $media->getPathRelativeToRoot();
        $source->put($path, Storage::disk('r2_public')->get($path));
        DB::table('media')->where('id', $media->id)->update(['disk' => 'public', 'conversions_disk' => 'public']);
        $migration = \Mockery::mock(R2UploadMigration::class)->makePartial();
        $migration->shouldReceive('deleteVerifiedSource')->once()->andThrow(new RuntimeException('Temporary cleanup failure'));
        $this->instance(R2UploadMigration::class, $migration);

        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertFailed();
        $this->assertSame('r2_public', $media->fresh()->disk);
        $source->assertExists($path);
        $this->assertNotEmpty($media->fresh()->getCustomProperty('r2_migration_sources'));

        $this->instance(R2UploadMigration::class, new R2UploadMigration);
        $this->artisan('uploads:migrate-to-r2', ['--delete-source' => true])->assertSuccessful();
        $source->assertMissing($path);
        Storage::disk('r2_public')->assertExists($path);
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
