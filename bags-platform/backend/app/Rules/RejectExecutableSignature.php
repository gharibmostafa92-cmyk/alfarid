<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class RejectExecutableSignature implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || $value->getRealPath() === false) {
            return;
        }

        $head = @file_get_contents($value->getRealPath(), false, null, 0, 2);
        if ($head === 'MZ') {
            $fail('نوع الملف المرفوع غير مدعوم.');
        }
    }
}
