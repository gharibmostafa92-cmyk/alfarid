<?php

declare(strict_types=1);

namespace App\Services\Shared;

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
     * Executes a callback within a transactional scope, purging stored files on rollback.
     *
     * @param  callable(self): mixed  $callback  Business transaction callback.
     * @param  list<array{0: UploadedFile, 1: string}>  $uploads  Optional files pre-uploaded outside transaction locks.
     * @return mixed Value returned by the business callback.
     *
     * @throws Throwable Re-throws the original exception if transaction fails.
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

        // Attach rollback cleanup to outer transaction to ensure savepoint rollbacks still purge remote storage.
        $nested = DB::transactionLevel() > 0;
        if ($nested) {
            $pending = $files;
            DB::afterRollBack(static function () use ($pending): void {
                $pending->deleteStoredQuietly();
            });
        }

        $callbackCompleted = false;
        try {
            $result = DB::transaction(function () use ($callback, $files, &$callbackCompleted): mixed {
                $result = $callback($files);
                $callbackCompleted = true;

                return $result;
            });
            $unused = array_diff(array_values($files->prepared), $files->claimed);
            $files->deletePathsQuietly($unused);
            $files->stored = array_values(array_diff($files->stored, $unused));

            return $result;
        } catch (Throwable $exception) {
            // Once the outer callback returns, a failed COMMIT acknowledgement
            // does not tell us whether the database references these files.
            // Leave them for reference-checked orphan cleanup.
            if ($nested || ! $callbackCompleted) {
                $files->deleteStoredQuietly();
            }

            throw $exception;
        }
    }

    /**
     * Stores an uploaded file on private R2 storage and tracks it for transaction rollback cleanup.
     *
     * @param  UploadedFile  $file  Uploaded file instance.
     * @param  string  $directory  Target storage path.
     * @return string Stored file path on disk.
     *
     * @throws RuntimeException If the cloud storage upload fails.
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
