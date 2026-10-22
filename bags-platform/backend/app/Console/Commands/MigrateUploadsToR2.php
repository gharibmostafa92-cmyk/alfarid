<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\R2UploadMigration;
use App\Services\Media\UuidPathGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MigrateUploadsToR2 extends Command
{
    protected $signature = 'uploads:migrate-to-r2 {--delete-source : Remove local copies only after SHA-256 verification}';

    protected $description = 'Move existing catalog media and private uploads to R2 with content verification';

    public function handle(R2UploadMigration $migration): int
    {
        $count = 0;
        Media::query()->where(fn ($query) => $query->where('disk', '!=', 'r2')->orWhere('conversions_disk', '!=', 'r2'))
            ->chunkById(100, function ($items) use ($migration, &$count): void {
                foreach ($items as $item) {
                    DB::transaction(function () use ($item, $migration, &$count): void {
                        $media = Media::query()->lockForUpdate()->findOrFail($item->id);
                        $base = app(UuidPathGenerator::class)->getPath($media);
                        if (! Storage::disk($media->disk)->exists($base.$media->file_name)) {
                            throw new RuntimeException('Missing media original; record unchanged: '.$media->id);
                        }
                        $sources = [];
                        foreach (array_unique([$media->disk, $media->conversions_disk ?: $media->disk]) as $disk) {
                            if ($disk === 'r2') {
                                continue;
                            }
                            foreach (Storage::disk($disk)->allFiles(rtrim($base, '/')) as $path) {
                                $migration->copy($disk, $path);
                                $sources[] = [$disk, $path];
                            }
                        }
                        $media->disk = 'r2';
                        $media->conversions_disk = 'r2';
                        $media->save();
                        if ($this->option('delete-source')) {
                            DB::afterCommit(function () use ($sources, $migration): void {
                                foreach ($sources as [$disk, $path]) {
                                    $migration->deleteVerifiedSource($disk, $path);
                                }
                            });
                        }
                        $count++;
                    });
                }
            });

        // Include orphaned uploads, excluding certificates and application internals.
        $directories = ['customer_documents', 'return_requests', 'supplier_deliveries', 'supplier_invoices', 'wholesale_payment_notices'];
        foreach (['public', 'local'] as $disk) {
            $paths = $disk === 'public' ? Storage::disk($disk)->allFiles() : collect($directories)
                ->flatMap(fn ($directory) => Storage::disk($disk)->allFiles($directory))->all();
            foreach ($paths as $path) {
                if (basename($path) === '.gitignore') {
                    continue;
                }
                $migration->copy($disk, $path);
                if ($this->option('delete-source')) {
                    $migration->deleteVerifiedSource($disk, $path);
                }
                $count++;
            }
        }
        $this->info('R2 migration verified: '.$count.' media records / remaining files processed.');

        return self::SUCCESS;
    }
}
