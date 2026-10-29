<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Transactional file upload manager for private cloud storage (R2).
 *
 * Automatically tracks and deletes uploaded files if the database transaction fails.
 */
class PrivateAttachments
{
    private array $stored = [];

    /**
     * Execute a callback within a transactional scope, deleting stored files if rolled back.
     *
     * @param  callable(self): mixed  $callback
     * @return mixed
     *
     * @throws Throwable
     */
    public static function transaction(callable $callback): mixed
    {
        $files = new self;
        try {
            return DB::transaction(fn () => $callback($files));
        } catch (Throwable $exception) {
            Storage::disk('r2')->delete($files->stored);
            throw $exception;
        }
    }

    /**
     * Store an uploaded file on private R2 storage and track it for transaction rollback cleanup.
     *
     * @param  UploadedFile  $file
     * @param  string  $directory
     * @return string
     *
     * @throws RuntimeException
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'r2');
        if ($path === false) {
            throw new RuntimeException('تعذر حفظ المرفق؛ لم يتم تسجيل العملية.');
        }
        $this->stored[] = $path;

        return $path;
    }
}
