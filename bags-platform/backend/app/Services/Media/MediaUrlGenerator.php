<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

class MediaUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        if ($this->getDiskName() === 'r2' && ! config('filesystems.disks.r2.url')) {
            // R2's S3 endpoint is private. The same URL handling covers originals
            // and generated product thumbnails.
            $ttl = max(60, min(604800, (int) config('filesystems.disks.r2.download_url_ttl_seconds', 3600)));

            return $this->getTemporaryUrl(now()->addSeconds($ttl));
        }

        return parent::getUrl();
    }
}
