<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Generates UUID-based storage paths to prevent enumeration and guessing of draft media assets.
 */
class UuidPathGenerator implements PathGenerator
{
    /**
     * Resolves the root storage directory for a media file.
     *
     * @param  Media  $media  Target media model instance.
     * @return string Relative directory path ending with a trailing slash.
     */
    public function getPath(Media $media): string
    {
        if ((string) config('media-library.prefix', '') !== '') {
            throw new \LogicException('MEDIA_PREFIX is not applied by the UUID path generator; leave it empty.');
        }
        // Avoid remote storage I/O during path generation; resolve legacy paths synchronously in memory.
        if ($media->getCustomProperty('path_layout') === 'uuid' && $media->uuid) {
            return $media->uuid.'/';
        }

        return $media->id.'/';
    }

    /**
     * Resolves storage directory for generated image conversions.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    /**
     * Resolves storage directory for responsive responsive image sets.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive-images/';
    }
}
