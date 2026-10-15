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
        return $media->uuid.'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $media->uuid.'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $media->uuid.'/responsive-images/';
    }
}
