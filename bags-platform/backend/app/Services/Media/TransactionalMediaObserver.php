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
        // Local/dev fallback: when MEDIA_CATALOG_DISK points at a non-R2 disk
        // (e.g. `public`), allow exactly that configured disk outside production
        // so developers can work without R2 credentials.
        $configuredCatalog = (string) config('filesystems.catalog_disk', 'r2_public');
        $localCatalog = app()->environment(['local', 'testing'])
            && $configuredCatalog !== 'r2' && $configuredCatalog !== 'r2_public'
            && $media->disk === $configuredCatalog
            && in_array($media->conversions_disk, ['', $configuredCatalog], true);
        // Store catalog entities in public storage while restricting sensitive customer uploads to private buckets.
        if (! $localDemo && ! $localCatalog && (! in_array($media->disk, ['r2', 'r2_public'], true)
            || ($media->conversions_disk && ! in_array($media->conversions_disk, ['r2', 'r2_public'], true)))) {
            throw new \LogicException('Uploaded media must be stored on R2.');
        }
        $media->conversions_disk = $media->conversions_disk ?: $media->disk;
        if (in_array('r2_public', [$media->disk, $media->conversions_disk], true)
            && (string) config('filesystems.disks.r2_public.bucket') !== ''
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
