<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

// مسارات الميديا بالـ uuid بدل الـ id المتسلسل، فصور المسودات مبتتخمنش بترقيم بسيط
class UuidPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        // M3: never hit the network here — this runs per URL (original + every
        // conversion). Legacy rows without a layout live under id/; the
        // media:backfill-path-layouts command persists the layout separately.
        if ($media->getCustomProperty('path_layout') === 'uuid' && $media->uuid) {
            return $media->uuid.'/';
        }

        return $media->id.'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive-images/';
    }
}
