<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

final class ContentDisposition
{
    public static function attachment(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'attachment.bin';
        if (! is_string($ascii) || ! str_contains($ascii, '.')) {
            $ascii = 'attachment.bin';
        }

        return HeaderUtils::makeDisposition('attachment', $base !== '' ? $base : 'attachment.bin', $ascii);
    }
}
