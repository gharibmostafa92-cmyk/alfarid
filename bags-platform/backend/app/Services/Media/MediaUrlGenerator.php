<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

class MediaUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        // H5: the private disk always yields a temporary URL — even when a public
        // URL is configured — so enabling public catalog URLs can never leak
        // private documents. Catalog media lives on r2_public (stable public URLs).
        if ($this->getDiskName() === 'r2') {
            $ttl = max(60, min(604800, (int) config('filesystems.disks.r2.download_url_ttl_seconds', 3600)));

            return $this->getTemporaryUrl(now()->addSeconds($ttl));
        }

        return parent::getUrl();
    }
}
