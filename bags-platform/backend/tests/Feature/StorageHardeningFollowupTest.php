<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Media\TransactionalMediaObserver;
use App\Services\PrivateAttachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class StorageHardeningFollowupTest extends TestCase
{
    public function test_media_cleanup_failure_does_not_abort_later_after_commit_callbacks(): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('removeAllFiles')->once()->andThrow(new RuntimeException('R2 unavailable'));
        $this->app->instance(Filesystem::class, $filesystem);
        $media = new Media;
        $media->id = 999;
        $media->uuid = 'dec1191a-41aa-42a4-8d92-fb76cf750d74';
        $media->setCustomProperty('path_layout', 'uuid');
        $laterCallbackRan = false;
        DB::transaction(function () use ($media, &$laterCallbackRan): void {
            app(TransactionalMediaObserver::class)->deleted($media);
            DB::afterCommit(function () use (&$laterCallbackRan): void {
                $laterCallbackRan = true;
            });
        });
        $this->assertTrue($laterCallbackRan);
    }

    public function test_prepared_upload_happens_before_the_database_transaction(): void
    {
        $file = $this->upload();
        $path = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($file): string {
            $this->assertGreaterThan(0, DB::transactionLevel());

            return $files->store($file, 'supplier_invoices/123');
        }, [[$file, 'supplier_invoices/pending']]);
        $this->assertSame('supplier_invoices/pending/test.pdf', $path);
        Storage::disk('r2')->assertExists($path);
    }

    public function test_replay_removes_the_unused_staged_upload(): void
    {
        $file = $this->upload();
        $result = PrivateAttachments::transaction(fn () => 'previous result', [[$file, 'supplier_invoices/pending']]);
        $this->assertSame('previous result', $result);
        Storage::disk('r2')->assertMissing('supplier_invoices/pending/test.pdf');
    }

    public function test_failed_operation_removes_its_prepared_upload(): void
    {
        $file = $this->upload();
        try {
            PrivateAttachments::transaction(function (PrivateAttachments $files) use ($file): never {
                $files->store($file, 'supplier_invoices/123');
                throw new RuntimeException('operation failed');
            }, [[$file, 'supplier_invoices/pending']]);
            $this->fail('Expected operation failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('operation failed', $exception->getMessage());
        }
        Storage::disk('r2')->assertMissing('supplier_invoices/pending/test.pdf');
    }

    private function upload(): UploadedFile
    {
        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('store')->once()->with(Mockery::pattern('~^supplier_invoices/pending/[a-f0-9-]{36}$~'), 'r2')->andReturnUsing(function (): string {
            $this->assertSame(0, DB::transactionLevel());
            Storage::disk('r2')->put('supplier_invoices/pending/test.pdf', 'receipt');

            return 'supplier_invoices/pending/test.pdf';
        });

        return $file;
    }
}
