<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

final class RejectExecutableSignature implements ValidationRule
{
    private const MAX_OOXML_UNCOMPRESSED_BYTES = 20_971_520; // 20 MB

    public function __construct(private readonly bool $allowOfficeOpenXml = false) {}

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

        $isPdfUpload = strtolower($value->getMimeType()) === 'application/pdf'
            || strtolower($value->getClientMimeType()) === 'application/pdf';
        $hasPdfHeader = str_starts_with($head, '%PDF-');
        if ($isPdfUpload && ! $hasPdfHeader) {
            $fail('ملف PDF لا يبدأ بترويسة PDF صالحة.');

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
        ) {
            $fail('نوع الملف المرفوع غير مدعوم.');

            return;
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            if (! $this->allowOfficeOpenXml || ! $this->isValidSafeXlsx($value->getRealPath())) {
                $fail('نوع الملف المرفوع غير مدعوم.');
            }

            return;
        }

        if ($hasPdfHeader) {
            $content = @file_get_contents($value->getRealPath());
            if ($content === false) {
                $fail('يحتوي ملف PDF على محتوى تنفيذي غير مسموح.');

                return;
            }

            // PDF names allow #xx escapes, so normalize them before checking active features.
            $decodedNames = preg_replace_callback(
                '/#([0-9a-f]{2})/i',
                static fn (array $match): string => chr(hexdec($match[1])),
                $content,
            );
            if ($decodedNames === null || preg_match(
                '/\/(?:JavaScript|JS|Launch|EmbeddedFiles|OpenAction|AA|RichMedia|SubmitForm|ImportData|URI)(?=[\x00\x09\x0A\x0C\x0D\x20()<>{}\[\]\/%%])/i',
                $decodedNames,
            ) === 1) {
                $fail('يحتوي ملف PDF على محتوى تنفيذي غير مسموح.');
            }
        }
    }

    private function isValidSafeXlsx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            $hasContentTypes = false;
            $hasWorkbook = false;
            $totalUncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    return false;
                }
                $name = (string) ($stat['name'] ?? '');
                if (
                    $name === ''
                    || str_starts_with($name, '/')
                    || str_starts_with($name, '\\')
                    || str_contains($name, '../')
                    || str_contains($name, '..\\')
                    || str_ends_with(strtolower($name), 'vbaproject.bin')
                ) {
                    return false;
                }

                $totalUncompressed += max(0, (int) ($stat['size'] ?? 0));
                if ($totalUncompressed > self::MAX_OOXML_UNCOMPRESSED_BYTES) {
                    return false;
                }

                if ($name === '[Content_Types].xml') {
                    $hasContentTypes = true;
                } elseif ($name === 'xl/workbook.xml') {
                    $hasWorkbook = true;
                }
            }

            return $hasContentTypes && $hasWorkbook;
        } finally {
            $zip->close();
        }
    }
}
