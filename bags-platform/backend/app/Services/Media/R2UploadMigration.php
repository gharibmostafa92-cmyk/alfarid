<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class R2UploadMigration
{
    public function copy(string $disk, string $path, string $targetDisk = 'r2'): void
    {
        $this->validateSource($disk, $path);
        $this->validateTarget($targetDisk);
        $hash = $this->hash($disk, $path);
        $target = Storage::disk($targetDisk);
        if (! $target->exists($path)) {
            $stream = Storage::disk($disk)->readStream($path);
            try {
                if (! $target->put($path, $stream)) {
                    throw new RuntimeException('R2 upload failed: '.$path);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        if (! hash_equals($hash, $this->hash($targetDisk, $path))) {
            throw new RuntimeException('R2 content verification failed; source preserved: '.$path);
        }
    }

    public function deleteVerifiedSource(string $disk, string $path, string $targetDisk = 'r2'): void
    {
        $this->validateSource($disk, $path);
        $this->validateTarget($targetDisk);
        if (! hash_equals($this->hash($disk, $path), $this->hash($targetDisk, $path))) {
            throw new RuntimeException('Source changed; local copy preserved: '.$path);
        }
        if (! Storage::disk($disk)->delete($path)) {
            throw new RuntimeException('Could not remove verified source: '.$path);
        }
    }

    private function validateTarget(string $targetDisk): void
    {
        if (! in_array($targetDisk, ['r2', 'r2_public'], true)) {
            throw new RuntimeException('Unsupported R2 target.');
        }
        if ($targetDisk === 'r2_public' && config('filesystems.disks.r2_public.bucket')
            && config('filesystems.disks.r2_public.bucket') === config('filesystems.disks.r2.bucket')) {
            throw new RuntimeException('Catalog media requires a separate public R2 bucket.');
        }
    }

    private function validateSource(string $disk, string $path): void
    {
        if (! in_array($disk, ['local', 'public', 'r2', 'r2_public'], true) || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new RuntimeException('Unsupported upload source.');
        }
    }

    private function hash(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Missing or unreadable upload: '.$path);
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
