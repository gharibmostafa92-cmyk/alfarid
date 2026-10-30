<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Product;
use App\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;

class TransactionalMediaObserver extends MediaObserver
{
    public function creating(Media $media): void
    {
        $localDemo = app()->environment(['local', 'testing'])
            && $media->getCustomProperty('storefront_demo') === true
            && ($media->model instanceof Product
                || ($media->model instanceof ProductVariant && str_starts_with((string) $media->model->product?->slug, 'demo-')))
            && $media->disk === 'public'
            && in_array($media->conversions_disk, ['', 'public'], true);
        // H5: catalog collections (Product/Variant/Category/Brand) use the public
        // bucket; everything else stays on the private bucket.
        if (! $localDemo && (! in_array($media->disk, ['r2', 'r2_public'], true)
            || ($media->conversions_disk && ! in_array($media->conversions_disk, ['r2', 'r2_public'], true)))) {
            throw new \LogicException('Uploaded media must be stored on R2.');
        }
        $media->conversions_disk = $media->conversions_disk ?: $media->disk;
        if (in_array('r2_public', [$media->disk, $media->conversions_disk], true)
            && config('filesystems.disks.r2_public.bucket')
            && config('filesystems.disks.r2_public.bucket') === config('filesystems.disks.r2.bucket')) {
            throw new \LogicException('Catalog media requires a separate public R2 bucket.');
        }
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
        $media->getConnection()->afterCommit(function () use ($copy): void {
            try {
                parent::deleted($copy);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
