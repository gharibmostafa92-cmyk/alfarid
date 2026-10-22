<?php

declare(strict_types=1);

namespace App\Services\Media;

use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;

class TransactionalMediaObserver extends MediaObserver
{
    public function creating(Media $media): void
    {
        if ($media->disk !== 'r2' || ($media->conversions_disk && $media->conversions_disk !== 'r2')) {
            throw new \LogicException('Uploaded media must be stored on R2.');
        }
        $media->conversions_disk = 'r2';
        parent::creating($media);
        $media->setCustomProperty('path_layout', 'uuid');
    }

    public function created(Media $media): void
    {
        if ($media->getConnection()->transactionLevel() > 0) {
            $media->getConnection()->afterRollBack(fn () => app(Filesystem::class)->removeAllFiles($media));
        }
    }

    public function deleted(Media $media): void
    {
        // Resolve legacy paths while the original file still exists.
        $copy = clone $media;
        $path = app(UuidPathGenerator::class)->getPath($copy);
        $copy->setCustomProperty('path_layout', $path === $copy->id.'/' ? 'id' : 'uuid');
        $media->getConnection()->afterCommit(fn () => parent::deleted($copy));
    }
}
