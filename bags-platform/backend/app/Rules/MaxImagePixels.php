<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validation rule guarding against image decompression bombs by capping total raster pixel count.
 */
class MaxImagePixels implements ValidationRule
{
    public function __construct(
        private int $maxPixels = 16000000,
        private int $maxDimension = 12000,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        $path = $value->getRealPath() ?: $value->getPathname();
        $mime = (string) $value->getMimeType();
        $isPdfUpload = strtolower($mime) === 'application/pdf'
            || strtolower($value->getClientMimeType()) === 'application/pdf';
        $hasPdfHeader = PdfHeader::contains($path);
        if ($isPdfUpload && ! $hasPdfHeader) {
            $fail('ملف PDF لا يبدأ بترويسة PDF صالحة.');

            return;
        }
        if ($hasPdfHeader) {
            return;
        }
        // Highly compressed images bypass filesize limits; cap total pixel count to prevent GD memory exhaustion bombs.
        $size = @getimagesize($value->getPathname());
        // M11: an unreadable/corrupt image must fail closed. Previously a
        // false return from getimagesize silently passed the rule.
        if (! is_array($size) || ! isset($size[0], $size[1]) || $size[0] < 1 || $size[1] < 1) {
            $fail('تعذر قراءة أبعاد الصورة.');

            return;
        }
        if ($size[0] > $this->maxDimension || $size[1] > $this->maxDimension || $this->maxPixels < $size[0] * $size[1]) {
            $fail('الصورة تتجاوز الحد الأقصى لعدد البكسلات ('.number_format($this->maxPixels).').');
        }
    }
}
