<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class RejectExecutableSignature implements ValidationRule
{
    /**
     * Rejects executable magic byte signatures (Windows PE, Linux ELF, macOS Mach-O, shell/PHP scripts).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || $value->getRealPath() === false) {
            return;
        }

        $head = @file_get_contents($value->getRealPath(), false, null, 0, 8);
        if ($head === false) {
            $fail('تعذر التحقق من سلامة الملف المرفوع.');
            return;
        }

        if (
            str_starts_with($head, 'MZ') // Windows PE (.exe, .dll, .sys)
            || str_starts_with($head, "\x7FELF") // Linux ELF
            || str_starts_with($head, "\xFE\xED\xFA\xCE") // Mach-O 32-bit big-endian
            || str_starts_with($head, "\xFE\xED\xFA\xCF") // Mach-O 64-bit big-endian
            || str_starts_with($head, "\xCE\xFA\xED\xFE") // Mach-O 32-bit little-endian
            || str_starts_with($head, "\xCF\xFA\xED\xFE") // Mach-O 64-bit little-endian
            || str_starts_with($head, "\xCA\xFE\xBA\xBE") // Mach-O Universal / Java class
            || str_starts_with($head, "\xBE\xBA\xFE\xCA") // Mach-O Universal swap
            || str_starts_with($head, '<?') // PHP / script opening tag
            || str_starts_with($head, '#!') // Shebang executable script
            || str_starts_with($head, "PK\x03\x04") // ZIP / JAR / Office / polyglot archive
        ) {
            $fail('نوع الملف المرفوع غير مدعوم.');

            return;
        }

        if (str_starts_with($head, '%PDF-')) {
            $content = @file_get_contents($value->getRealPath(), false, null, 0, 5242880);
            if ($content === false || preg_match('/\/(JavaScript|JS|Launch|EmbeddedFiles|OpenAction)\b/i', $content) === 1) {
                $fail('يحتوي ملف PDF على محتوى تنفيذي غير مسموح.');
            }
        }
    }
}
