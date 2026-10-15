<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

// مسارات الميديا بالـ uuid بدل الـ id المتسلسل، فصور المسودات مبتتخمنش بترقيم بسيط
class UuidPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        if (! $media->uuid || $media->getCustomProperty('path_layout') === 'id') {
            return $media->id.'/';
        }

        if (! $media->hasCustomProperty('path_layout')) {
            // Cache on this model instance; the backfill command persists it for future requests.
            $media->setCustomProperty('path_layout', Storage::disk($media->disk)->exists($media->id.'/'.$media->file_name) ? 'id' : 'uuid');
            if ($media->getCustomProperty('path_layout') === 'id') {
                return $media->id.'/';
            }
        }

        return $media->uuid.'/';
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
