<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    private array $prepared = [];

    private array $claimed = [];

    /**
     * Execute a callback within a transactional scope, deleting stored files if rolled back.
     *
     * @param  callable(self): mixed  $callback
     *
     * @throws Throwable
     */
    public static function transaction(callable $callback, array $uploads = []): mixed
    {
        $files = new self;
        try {
            foreach ($uploads as [$file, $directory]) {
                if ($file instanceof UploadedFile) {
                    if (isset($files->prepared[spl_object_id($file)])) {
                        continue;
                    }
                    $files->prepared[spl_object_id($file)] = $files->store($file, $directory.'/'.Str::uuid());
                }
            }
        } catch (Throwable $exception) {
            $files->deleteStoredQuietly();
            throw $exception;
        }

        // A successful savepoint is not a committed operation. Keep rollback
        // cleanup attached to an existing outer transaction. Invoke this API
        // before opening an outer transaction to keep network I/O outside it.
        // The attachment sweeper handles process death and failed deletes.
        if (DB::transactionLevel() > 0) {
            $pending = $files;
            DB::afterRollBack(static function () use ($pending): void {
                $pending->deleteStoredQuietly();
            });
        }

        try {
            $result = DB::transaction(fn () => $callback($files));
            $unused = array_diff(array_values($files->prepared), $files->claimed);
            $files->deletePathsQuietly($unused);
            $files->stored = array_values(array_diff($files->stored, $unused));

            return $result;
        } catch (Throwable $exception) {
            // H2: never let a failing R2 cleanup replace the original error
            // (disk has throw => true). The caller must see the real cause.
            $files->deleteStoredQuietly();

            throw $exception;
        }
    }

    /**
     * Store an uploaded file on private R2 storage and track it for transaction rollback cleanup.
     *
     *
     * @throws RuntimeException
     */
    public function store(UploadedFile $file, string $directory): string
    {
        if (isset($this->prepared[spl_object_id($file)])) {
            $path = $this->prepared[spl_object_id($file)];
            $this->claimed[] = $path;

            return $path;
        }
        $path = $file->store($directory, 'r2');
        if ($path === false) {
            throw new RuntimeException('تعذر حفظ المرفق؛ لم يتم تسجيل العملية.');
        }
        $this->stored[] = $path;

        return $path;
    }

    /**
     * Best-effort R2 cleanup that never throws: a failing delete must not
     * replace the original business exception (disk has throw => true).
     */
    public function deleteStoredQuietly(): void
    {
        if ($this->stored === []) {
            return;
        }

        $paths = $this->stored;
        $this->stored = [];

        $this->deletePathsQuietly($paths);
    }

    private function deletePathsQuietly(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        try {
            Storage::disk('r2')->delete($paths);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
