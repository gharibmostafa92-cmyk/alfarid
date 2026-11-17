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

    private const MAX_OOXML_FILES = 200;

    public function __construct(
        private readonly bool $allowOfficeOpenXml = false,
        private readonly int $maxOoxmlBytes = self::MAX_OOXML_UNCOMPRESSED_BYTES,
    ) {}

    /**
     * Rejects executable magic byte signatures (Windows PE, Linux ELF, macOS Mach-O, shell/PHP scripts).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || $value->getRealPath() === false) {
            return;
        }

        // ISO 32000-1 allows the %PDF- marker anywhere within the first
        // 1024 bytes (preamble), so a header strictly at offset zero is not
        // required; embedded polyglots are still caught by the magic-byte
        // and active-content checks below.
        $head = @file_get_contents($value->getRealPath(), false, null, 0, 1024);
        if ($head === false) {
            $fail('تعذر التحقق من سلامة الملف المرفوع.');

            return;
        }

        $isPdfUpload = strtolower($value->getMimeType()) === 'application/pdf'
            || strtolower($value->getClientMimeType()) === 'application/pdf';
        $hasPdfHeader = PdfHeader::contains($value->getRealPath());
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
            // NOTE: modern PDFs (1.5+) routinely compress catalogs and
            // actions inside FlateDecode object streams (/ObjStm), which no
            // plaintext scan can inspect. This check is therefore only a
            // first line of defense: PDFs must only ever be served with
            // Content-Disposition: attachment and X-Content-Type-Options:
            // nosniff (see CustomerController::downloadDocument), never inline.
            $content = @file_get_contents($value->getRealPath());
            if ($content === false) {
                $fail('يحتوي ملف PDF على محتوى تنفيذي غير مسموح.');

                return;
            }

            // Decode #xx only inside PDF name tokens that actually contain an
            // escape. Plain /Name tokens are already covered by the $active
            // scan on $content, so collecting them again would duplicate the
            // whole file in memory (O(n) -> O(k) where k is escaped tokens).
            $active = '/\/(?:JavaScript|JS|Launch|EmbeddedFiles|OpenAction|AA|RichMedia|SubmitForm|ImportData|URI)(?=[\x00\x09\x0A\x0C\x0D\x20()<>{}\[\]\/%%])/i';
            $names = '';
            if (preg_match_all('/\/[A-Za-z0-9#]*#[0-9a-fA-F]{2}[A-Za-z0-9#]{0,60}/', $content, $found) > 0) {
                foreach ($found[0] as $token) {
                    $names .= preg_replace_callback(
                        '/#([0-9a-f]{2})/i',
                        static fn (array $match): string => chr(hexdec($match[1])),
                        $token,
                    ).' ';
                }
            }
            if (preg_match($active, $content) === 1 || ($names !== '' && preg_match($active, $names) === 1)) {
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
            // A forged archive with thousands of entries alone can stall
            // spreadsheet parsers; cap the entry count first.
            if ($zip->numFiles > self::MAX_OOXML_FILES) {
                return false;
            }

            $hasContentTypes = false;
            $hasWorkbook = false;
            $totalUncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    return false;
                }
                $name = (string) ($stat['name'] ?? '');
                $lower = strtolower($name);
                if (
                    $name === ''
                    || str_starts_with($name, '/')
                    || str_starts_with($name, '\\')
                    || str_contains($name, '../')
                    || str_contains($name, '..\\')
                    || str_ends_with($lower, 'vbaproject.bin')
                    || str_contains($lower, 'macrosheets')
                    || str_contains($lower, 'externallinks')
                    || str_contains($lower, 'embeddings')
                    || str_contains($lower, 'activex/')
                ) {
                    return false;
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                // Never trust the central-directory size header: it is fully
                // attacker-controlled (header-spoofed ZIP bombs). Count the
                // bytes actually produced by the decompressor instead, bounded
                // so a bomb aborts the scan instead of the worker.
                $seen = $this->streamedEntryBytes($zip, $i);
                if ($seen === null || $seen > $this->maxOoxmlBytes) {
                    return false;
                }
                $totalUncompressed += $seen;
                if ($totalUncompressed > $this->maxOoxmlBytes) {
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

    /**
     * Count the real decompressed bytes of one archive entry, or null when
     * the entry cannot be streamed safely.
     */
    private function streamedEntryBytes(ZipArchive $zip, int $index): ?int
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            return null;
        }

        try {
            $seen = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    return null;
                }
                $seen += strlen($chunk);
                if ($seen > $this->maxOoxmlBytes) {
                    return $seen;
                }
            }

            return $seen;
        } finally {
            fclose($stream);
        }
    }
}
