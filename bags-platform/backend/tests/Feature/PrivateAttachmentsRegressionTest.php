<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PrivateAttachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PrivateAttachmentsRegressionTest extends TestCase
{
    public function test_a_successful_inner_upload_is_removed_when_its_outer_transaction_rolls_back(): void
    {
        $original = new RuntimeException('outer operation failed');
        $path = null;

        try {
            DB::transaction(function () use (&$path, $original): void {
                $path = PrivateAttachments::transaction(fn (PrivateAttachments $files) => $files->store(
                    UploadedFile::fake()->create('receipt.pdf'), 'wholesale_payment_notices',
                ));
                Storage::disk('r2')->assertExists($path);

                throw $original;
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception);
        }

        Storage::disk('r2')->assertMissing($path);
    }

    public function test_a_retried_outer_transaction_keeps_only_the_successful_upload(): void
    {
        $paths = [];
        DB::transaction(function () use (&$paths): void {
            $paths[] = PrivateAttachments::transaction(fn (PrivateAttachments $files) => $files->store(
                UploadedFile::fake()->create('receipt.pdf'), 'wholesale_payment_notices',
            ));
            if (count($paths) === 1) {
                throw new RuntimeException('deadlock detected');
            }
        }, 2);

        $this->assertCount(2, $paths);
        Storage::disk('r2')->assertMissing($paths[0]);
        Storage::disk('r2')->assertExists($paths[1]);
    }

    public function test_failed_cleanup_preserves_the_original_business_exception(): void
    {
        $original = new RuntimeException('business operation failed');

        try {
            PrivateAttachments::transaction(function (PrivateAttachments $files) use ($original): void {
                $files->store(UploadedFile::fake()->create('receipt.pdf'), 'wholesale_payment_notices');
                Storage::shouldReceive('disk')->with('r2')->andThrow(new RuntimeException('storage unavailable'));

                throw $original;
            });
            $this->fail('The business operation should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception);
        }
    }
}
