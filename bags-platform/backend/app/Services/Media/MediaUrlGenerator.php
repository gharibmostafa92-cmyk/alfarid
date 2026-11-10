<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * Custom URL generator enforcing signed pre-signed URLs on private media disks.
 */
class MediaUrlGenerator extends DefaultUrlGenerator
{
    /**
     * Resolves the media download URL, enforcing short-lived signed URLs for private R2 storage.
     */
    public function getUrl(): string
    {
        // Force signed temporary URLs on private R2 storage to prevent accidental data leakage.
        if ($this->getDiskName() === 'r2') {
            $ttl = max(60, min(3600, (int) config('filesystems.disks.r2.download_url_ttl_seconds', 300)));

            return $this->getTemporaryUrl(now()->addSeconds($ttl));
        }

        return parent::getUrl();
    }
}
