<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\R2UploadMigration;
use App\Services\Media\UuidPathGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Migrates local catalog media and private uploads to R2 cloud storage with content verification.
 */
class MigrateUploadsToR2 extends Command
{
    protected $signature = 'uploads:migrate-to-r2 {--delete-source : Remove local copies only after SHA-256 verification} {--catalog-only : Copy catalog media only, excluding other uploads and orphan files}';

    protected $description = 'Move existing catalog media and private uploads to R2 with content verification';

    public function handle(R2UploadMigration $migration): int
    {
        $count = 0;
        $failures = [];
        $catalogPrefixes = [];
        Media::query()
            ->when($this->option('catalog-only'), fn ($query) => $query->whereIn('model_type', [Product::class, ProductVariant::class, Category::class, Brand::class]))
            ->chunkById(100, function ($items) use ($migration, &$count, &$failures, &$catalogPrefixes): void {
                foreach ($items as $item) {
                    if ($this->targetDiskFor($item) === 'r2_public') {
                        $catalogPrefixes[$item->id] = true;
                        if ($item->uuid) {
                            $catalogPrefixes[$item->uuid] = true;
                        }
                    }
                    try {
                        if ($this->copyMedia($item, $migration)) {
                            $count++;
                        }
                        if ($this->option('delete-source')) {
                            $this->deleteSources($item->id, $migration);
                        }
                    } catch (Throwable $e) {
                        $failures[] = $item->id.': '.$e->getMessage();
                        $this->error('Skipped media '.$item->id.': '.$e->getMessage());
                    }
                }
            });

        // Include orphaned uploads, excluding certificates and application internals.
        $directories = ['customer_documents', 'return_requests', 'supplier_deliveries', 'supplier_invoices', 'wholesale_payment_notices'];
        foreach ($this->option('catalog-only') ? [] : ['public', 'local'] as $disk) {
            try {
                $paths = $disk === 'public' ? Storage::disk($disk)->allFiles() : collect($directories)
                    ->flatMap(fn ($directory) => Storage::disk($disk)->allFiles($directory))->all();
            } catch (Throwable $e) {
                $failures[] = $disk.': '.$e->getMessage();
                $this->error('Skipped disk '.$disk.': '.$e->getMessage());

                continue;
            }
            foreach ($paths as $path) {
                if (basename($path) === '.gitignore') {
                    continue;
                }
                if ($disk === 'public' && isset($catalogPrefixes[explode('/', $path, 2)[0]])) {
                    continue;
                }
                try {
                    $migration->copy($disk, $path);
                    if ($this->option('delete-source')) {
                        $migration->deleteVerifiedSource($disk, $path);
                    }
                    $count++;
                } catch (Throwable $e) {
                    $failures[] = $path.': '.$e->getMessage();
                    $this->error('Skipped file '.$path.': '.$e->getMessage());
                }
            }
        }
        if ($failures !== []) {
            $this->error('R2 migration finished with '.count($failures).' failures; rerun after fixing them.');
            $this->info('R2 migration verified: '.$count.' media records / remaining files processed.');

            return self::FAILURE;
        }
        // The --delete-source flag only removes SHA-256-verified copies, ensuring zero data loss
        // during transfer while private documents are routed exclusively through R2.
        $this->info('R2 migration verified: '.$count.' media records / remaining files processed.');

        return self::SUCCESS;
    }

    private function copyMedia(Media $snapshot, R2UploadMigration $migration): bool
    {
        $target = $this->targetDiskFor($snapshot);
        $base = app(UuidPathGenerator::class)->getPath($snapshot);
        $rekey = $target === 'r2_public' && $base !== $snapshot->uuid.'/';
        $sourceDisks = array_unique([$snapshot->disk, $snapshot->conversions_disk ?: $snapshot->disk]);
        if ($sourceDisks === [$target] && ! $rekey) {
            return false;
        }
        if ($rekey && ! $snapshot->uuid) {
            throw new \RuntimeException('Catalog media requires a UUID: '.$snapshot->id);
        }
        if (! Storage::disk($snapshot->disk)->exists($base.$snapshot->file_name)) {
            throw new \RuntimeException('Missing media original; record unchanged: '.$snapshot->id);
        }

        $targetBase = $rekey ? $snapshot->uuid.'/' : $base;
        $sources = $snapshot->getCustomProperty('r2_migration_sources', []);
        foreach ($sourceDisks as $disk) {
            if ($disk === $target && ! $rekey) {
                continue;
            }
            foreach (Storage::disk($disk)->allFiles(rtrim($base, '/')) as $path) {
                $targetPath = $targetBase.substr($path, strlen($base));
                $migration->copy($disk, $path, $target, $targetPath);
                $sources[] = [$disk, $path, $target, $targetPath];
            }
        }

        DB::transaction(function () use ($snapshot, $target, $rekey, $sources): void {
            $media = Media::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ($media->getRawOriginal() !== $snapshot->getRawOriginal()) {
                throw new \RuntimeException('Media changed during copy; source preserved: '.$snapshot->id);
            }

            $media->disk = $target;
            $media->conversions_disk = $target;
            if ($rekey) {
                $media->setCustomProperty('path_layout', 'uuid');
            }
            $media->setCustomProperty('r2_migration_sources', $sources);
            $media->save();
        });

        return true;
    }

    private function targetDiskFor(Media $media): string
    {
        // Catalog may explicitly use private R2 signed URLs. Local dev disks
        // still migrate to the standard public catalog destination by default.
        return in_array($media->model_type, [Product::class, ProductVariant::class, Category::class, Brand::class], true)
            ? (config('filesystems.catalog_disk') === 'r2' ? 'r2' : 'r2_public')
            : 'r2';
    }

    private function deleteSources(int $id, R2UploadMigration $migration): void
    {
        // The manifest survives the metadata commit, so failed cleanup can be retried.
        DB::transaction(function () use ($id, $migration): void {
            $media = Media::query()->lockForUpdate()->findOrFail($id);
            if (! $media->hasCustomProperty('r2_migration_sources')) {
                return;
            }
            foreach ($media->getCustomProperty('r2_migration_sources', []) as $source) {
                $migration->deleteVerifiedSource(...$source);
            }
            $media->forgetCustomProperty('r2_migration_sources');
            $media->save();
        });
    }
}
