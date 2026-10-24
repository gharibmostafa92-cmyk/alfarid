<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class MaxImagePixels implements ValidationRule
{
    public function __construct(private int $maxPixels = 16000000) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        // F7: a single-color 6000x6000 PNG compresses under 5MB, so the
        // max:kilobytes rule cannot stop a decompression bomb — GD needs
        // ~144MB to rasterize it. Cap total pixels instead of edge length.
        $size = @getimagesize($value->getPathname());
        if (is_array($size) && $this->maxPixels < $size[0] * $size[1]) {
            $fail('الصورة تتجاوز الحد الأقصى لعدد البكسلات ('.number_format($this->maxPixels).').');
        }
    }
}
