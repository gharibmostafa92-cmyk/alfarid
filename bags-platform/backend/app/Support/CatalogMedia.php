<?php

declare(strict_types=1);

namespace App\Support;

final class CatalogMedia
{
    public static function disk(): string
    {
        return (string) config('filesystems.catalog_disk', 'r2_public');
    }
}
