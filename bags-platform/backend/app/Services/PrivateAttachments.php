<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PrivateAttachments
{
    private array $stored = [];

    public static function transaction(callable $callback): mixed
    {
        $files = new self;
        try {
            return DB::transaction(fn () => $callback($files));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($files->stored);
            throw $exception;
        }
    }

    public function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'local');
        if ($path === false) {
            throw new RuntimeException('تعذر حفظ المرفق؛ لم يتم تسجيل العملية.');
        }
        $this->stored[] = $path;

        return $path;
    }
}
