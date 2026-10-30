<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * F13: afterRollBack cleanup never runs when the process itself dies
 * (max_execution_time, OOM, worker restart) — the DB rolls back on
 * disconnect but the orphaned R2 files stay. This command deletes uuid/
 * folders older than the grace window that no media row references.
 * Compare in batches; deletions are idempotent, so re-runs are safe.
 */
class PruneOrphanMediaFiles extends Command
{
    protected $signature = 'media:prune-orphans {--disk=r2_public : المجلد العام للكتالوج} {--older-than=60 : عمر المجلد بالدقائق قبل اعتباره يتيمًا} {--dry-run : عرض فقط دون حذف} {--staging-only : تنظيف صفوف الرفع المرحلي دون مسح مجلدات التخزين}';

    protected $description = 'Delete orphaned uuid/ media folders with no matching media row';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if (! in_array($diskName, ['r2', 'r2_public'], true) || $olderThan === false || $olderThan < 60) {
            $this->error('Use an R2 disk and a grace period of at least 60 minutes.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($olderThan)->getTimestamp();

        $disk = Storage::disk($diskName);
        $pruned = 0;
        $skipped = 0;
        $failures = 0;

        // A terminated video upload can leave its staging row behind. Remove
        // expired staging media so the UUID sweep can reclaim those files too.
        Media::query()->whereIn('model_type', [Product::class, ProductVariant::class])->whereIn('collection_name', ['video_staging', 'images_staging'])
            ->where('disk', $diskName)->where('created_at', '<', now()->subMinutes($olderThan))
            ->chunkById(100, function ($media) use ($dryRun, &$failures): void {
                foreach ($media as $item) {
                    if ($dryRun) {
                        $this->line('Expired staging media: '.$item->id);

                        continue;
                    }
                    try {
                        $item->delete();
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->error('Failed to remove staging media '.$item->id.': '.$exception->getMessage());
                    }
                }
            });

        if ($this->option('staging-only')) {
            $this->info('Expired staging media processed.');

            return $failures === 0 ? self::SUCCESS : self::FAILURE;
        }

        $process = function (array $candidates) use ($disk, $dryRun, $cutoff, &$pruned, &$skipped, &$failures): void {
            $referenced = DB::table('media')->whereIn('uuid', array_keys($candidates))->pluck('uuid')->flip();
            foreach ($candidates as $base => $dir) {
                if ($referenced->has($base)) {
                    continue;
                }
                try {
                    $newest = 0;
                    $count = 0;
                    foreach ($disk->getDriver()->listContents($dir, true) as $file) {
                        if ($file->isFile()) {
                            $count++;
                            $newest = max($newest, $file->lastModified() ?? PHP_INT_MAX);
                        }
                    }
                    if ($count === 0 || $newest >= $cutoff) {
                        $skipped++;

                        continue;
                    }
                    if (DB::table('media')->where('uuid', $base)->exists()) {
                        continue;
                    }
                    if ($dryRun) {
                        $this->line("[dry-run] would delete {$dir} ({$count} files)");
                    } elseif (! $disk->deleteDirectory($dir)) {
                        throw new \RuntimeException('Storage did not confirm deletion.');
                    }
                    $pruned++;
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);
                    $this->error('Could not process '.$dir.'; rerun after checking storage access.');
                }
            }
        };
        try {
            $batch = [];
            foreach ($disk->getDriver()->listContents('', false) as $entry) {
                $dir = $entry->path();
                if ($entry->isDir() && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $dir)) {
                    $batch[$dir] = $dir;
                    if (count($batch) === 500) {
                        $process($batch);
                        $batch = [];
                    }
                }
            }
            if ($batch !== []) {
                $process($batch);
            }
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Could not list media storage.');
        }

        $this->info("Pruned {$pruned} orphan media directorie(s); {$skipped} recent upload(s) left for the grace window.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
