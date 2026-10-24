<?php

declare(strict_types=1);

namespace App\Console\Commands;

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

class MigrateUploadsToR2 extends Command
{
    protected $signature = 'uploads:migrate-to-r2 {--delete-source : Remove local copies only after SHA-256 verification}';

    protected $description = 'Move existing catalog media and private uploads to R2 with content verification';

    public function handle(R2UploadMigration $migration): int
    {
        $count = 0;
        $failures = [];
        $catalogPrefixes = [];
        Media::query()
            ->chunkById(100, function ($items) use ($migration, &$count, &$failures, &$catalogPrefixes): void {
                foreach ($items as $item) {
                    if ($this->targetDiskFor($item) === 'r2_public') {
                        $catalogPrefixes[$item->id] = true;
                        if ($item->uuid) {
                            $catalogPrefixes[$item->uuid] = true;
                        }
                    }
                    try {
                        DB::transaction(function () use ($item, $migration, &$count): void {
                            $media = Media::query()->lockForUpdate()->findOrFail($item->id);
                            $target = $this->targetDiskFor($media);
                            $base = app(UuidPathGenerator::class)->getPath($media);
                            $rekey = $target === 'r2_public' && $base !== $media->uuid.'/';
                            if ($media->disk === $target && ($media->conversions_disk ?: $media->disk) === $target && ! $rekey) {
                                return;
                            }
                            if ($rekey && ! $media->uuid) {
                                throw new \RuntimeException('Catalog media requires a UUID: '.$media->id);
                            }
                            $targetBase = $rekey ? $media->uuid.'/' : $base;
                            if (! Storage::disk($media->disk)->exists($base.$media->file_name)) {
                                // M10: a single missing original must not abort the run;
                                // record it and continue with the next row.
                                throw new \RuntimeException('Missing media original; record unchanged: '.$media->id);
                            }
                            $sources = $media->getCustomProperty('r2_migration_sources', []);
                            foreach (array_unique([$media->disk, $media->conversions_disk ?: $media->disk]) as $disk) {
                                if ($disk === $target && ! $rekey) {
                                    continue;
                                }
                                foreach (Storage::disk($disk)->allFiles(rtrim($base, '/')) as $path) {
                                    $targetPath = $targetBase.substr($path, strlen($base));
                                    $migration->copy($disk, $path, $target, $targetPath);
                                    $sources[] = [$disk, $path, $target, $targetPath];
                                }
                            }
                            $media->disk = $target;
                            $media->conversions_disk = $target;
                            if ($rekey) {
                                $media->setCustomProperty('path_layout', 'uuid');
                            }
                            $media->setCustomProperty('r2_migration_sources', $sources);
                            $media->save();
                            $count++;
                        });
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
        foreach (['public', 'local'] as $disk) {
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
        // M10: --delete-source only removes SHA-256-verified copies, and the app
        // reads private documents exclusively via Storage::disk('r2') — no
        // explicit local/public reads remain outside this command.
        $this->info('R2 migration verified: '.$count.' media records / remaining files processed.');

        return self::SUCCESS;
    }

    private function targetDiskFor(Media $media): string
    {
        // H5: catalog models land on the public bucket; everything else stays private.
        return in_array($media->model_type, [Product::class, ProductVariant::class, Category::class, Brand::class], true)
            ? 'r2_public'
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
