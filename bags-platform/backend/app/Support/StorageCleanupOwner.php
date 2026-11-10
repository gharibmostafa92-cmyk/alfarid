<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class StorageCleanupOwner
{
    public const MARKER = '.alfarid-cleanup-owner';

    public static function assertMatches(FilesystemAdapter $disk): void
    {
        $expected = trim((string) config('filesystems.cleanup_owner'));
        if ($expected === '' || ! $disk->exists(self::MARKER)
            || ! hash_equals($expected, trim((string) $disk->get(self::MARKER)))) {
            throw new RuntimeException('Storage cleanup owner marker is missing or does not match this database.');
        }
    }
}
