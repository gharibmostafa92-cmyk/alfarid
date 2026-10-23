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
        Media::query()
            ->chunkById(100, function ($items) use ($migration, &$count, &$failures): void {
                foreach ($items as $item) {
                    try {
                        DB::transaction(function () use ($item, $migration, &$count): void {
                            $media = Media::query()->lockForUpdate()->findOrFail($item->id);
                            $target = $this->targetDiskFor($media);
                            if ($media->disk === $target && ($media->conversions_disk ?: $media->disk) === $target) {
                                return;
                            }
                            $base = app(UuidPathGenerator::class)->getPath($media);
                            if (! Storage::disk($media->disk)->exists($base.$media->file_name)) {
                                // M10: a single missing original must not abort the run;
                                // record it and continue with the next row.
                                throw new \RuntimeException('Missing media original; record unchanged: '.$media->id);
                            }
                            $sources = [];
                            foreach (array_unique([$media->disk, $media->conversions_disk ?: $media->disk]) as $disk) {
                                if ($disk === $target) {
                                    continue;
                                }
                                foreach (Storage::disk($disk)->allFiles(rtrim($base, '/')) as $path) {
                                    $migration->copy($disk, $path, $target);
                                    $sources[] = [$disk, $path, $target];
                                }
                            }
                            $media->disk = $target;
                            $media->conversions_disk = $target;
                            $media->save();
                            if ($this->option('delete-source')) {
                                DB::afterCommit(function () use ($sources, $migration): void {
                                    foreach ($sources as [$disk, $path, $target]) {
                                        $migration->deleteVerifiedSource($disk, $path, $target);
                                    }
                                });
                            }
                            $count++;
                        });
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
}
