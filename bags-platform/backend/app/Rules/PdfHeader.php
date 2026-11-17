<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * Single source of truth for PDF-header detection (task3 HIGH-05).
 *
 * ISO 32000-1 allows the %PDF- marker anywhere within the first 1024 bytes,
 * but only a whitespace/BOM preamble is legal. Anything else (binary
 * garbage, image magic, executable headers) marks an Image-PDF polyglot
 * and must NOT be treated as a clean PDF.
 */
final class PdfHeader
{
    private const WINDOW_BYTES = 1024;

    public static function contains(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, self::WINDOW_BYTES);
        if (! is_string($head)) {
            return false;
        }
        $pos = strpos($head, '%PDF-');
        if ($pos === false) {
            return false;
        }

        return preg_match('/\A(?:[\x09\x0A\x0B\x0C\x0D\x20]|\xEF\xBB\xBF)*\z/', substr($head, 0, $pos)) === 1;
    }
}
