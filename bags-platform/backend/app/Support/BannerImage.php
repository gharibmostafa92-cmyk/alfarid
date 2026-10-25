<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class BannerImage
{
    public static function disk(): string
    {
        return (string) config('filesystems.banners_disk', 'r2');
    }

    /** Returns null instead of breaking the endpoint when storage is unavailable. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        try {
            $disk = Storage::disk(self::disk());
            if (method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()) {
                $ttl = max(60, min(604800, (int) config('filesystems.disks.r2.download_url_ttl_seconds', 3600)));

                return $disk->temporaryUrl($path, now()->addSeconds($ttl));
            }

            return $disk->url($path);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
