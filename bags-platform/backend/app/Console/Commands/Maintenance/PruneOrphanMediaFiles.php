<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\StorageCleanupOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Handles cleanup when afterRollBack callbacks never run due to abnormal process termination
 * (max_execution_time, OOM, worker restart) — the DB rolls back on
 * disconnect but the orphaned R2 files stay. This command deletes uuid/
 * folders older than the grace window that no media row references.
 * Compare in batches; deletions are idempotent, so re-runs are safe.
 */
class PruneOrphanMediaFiles extends Command
{
    protected $signature = 'media:prune-orphans {--disk=r2_public : المجلد العام للكتالوج} {--older-than=60 : عمر المجلد بالدقائق قبل اعتباره يتيمًا} {--dry-run : عرض فقط دون حذف} {--staging-only : تنظيف صفوف الرفع المرحلي دون مسح مجلدات التخزين} {--max-deletions=1000 : Circuit breaker; abort when a run would delete more directories}';

    protected $description = 'Delete orphaned uuid/ media folders with no matching media row';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if (! in_array($diskName, ['r2', 'r2_public'], true) || $olderThan === false || $olderThan < 60) {
            $this->error('Use an R2 disk and a grace period of at least 60 minutes.');

            return self::FAILURE;
        }
        $maxDeletions = filter_var($this->option('max-deletions'), FILTER_VALIDATE_INT);
        if ($maxDeletions === false || $maxDeletions < 1) {
            $this->error('--max-deletions must be a positive integer.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        if ($diskName === 'r2' && ! $this->option('staging-only')) {
            $this->error('Only known staging rows can be pruned on the private disk.');

            return self::FAILURE;
        }
        $privateBucket = config('filesystems.disks.r2.bucket');
        if ($diskName === 'r2_public' && $privateBucket && $privateBucket === config('filesystems.disks.r2_public.bucket')) {
            $this->error('Catalog cleanup requires a separate public bucket.');

            return self::FAILURE;
        }
        $cutoff = now()->subMinutes($olderThan)->getTimestamp();

        $disk = Storage::disk($diskName);
        if (! $dryRun) {
            try {
                StorageCleanupOwner::assertMatches($disk);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Storage cleanup ownership could not be verified.');

                return self::FAILURE;
            }
        }
        $pruned = 0;
        $skipped = 0;
        $failures = 0;

        $stagingOnly = (bool) $this->option('staging-only');
        $staging = Media::query()->useWritePdo()->whereIn('model_type', [(new Product)->getMorphClass(), (new ProductVariant)->getMorphClass()])
            ->whereIn('collection_name', ['video_staging', 'images_staging'])
            ->where('disk', $diskName)->where('created_at', '<', now()->subMinutes($olderThan));
        $stagingIds = (clone $staging)->orderBy('id')->limit($maxDeletions)->pluck('id');
        $budgetExceeded = fn () => $this->error("Aborted: --max-deletions={$maxDeletions} exceeded; no media was deleted.");
        if ($stagingIds->count() === $maxDeletions && (clone $staging)->where('id', '>', $stagingIds->last())->exists()) {
            $budgetExceeded();

            return self::FAILURE;
        }

        $removeStaging = function () use ($staging, $stagingIds, $diskName, $dryRun, &$failures): void {
            foreach ($stagingIds->chunk(100) as $ids) {
                foreach ((clone $staging)->whereIn('id', $ids)->get() as $item) {
                    if ($dryRun) {
                        $this->line('Expired staging media: '.$item->id);

                        continue;
                    }
                    try {
                        if ($item->conversions_disk && $item->conversions_disk !== $diskName) {
                            StorageCleanupOwner::assertMatches(Storage::disk($item->conversions_disk));
                        }
                        $item->delete();
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->error('Failed to remove staging media '.$item->id.': '.$exception->getMessage());
                    }
                }
            }
        };

        if ($stagingOnly) {
            $removeStaging();
            $this->info('Expired staging media processed.');

            return $failures === 0 ? self::SUCCESS : self::FAILURE;
        }

        $halted = false;
        $orphans = [];
        $remaining = $maxDeletions - $stagingIds->count();
        $process = function (array $candidates) use ($disk, $cutoff, $remaining, $budgetExceeded, &$orphans, &$skipped, &$failures, &$halted): void {
            $referenced = DB::table('media')->useWritePdo()->whereIn('uuid', array_keys($candidates))->pluck('uuid')->flip();
            foreach ($candidates as $base => $dir) {
                if ($halted) {
                    return;
                }
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
                    if (count($orphans) >= $remaining) {
                        $halted = true;
                        $failures++;
                        $budgetExceeded();

                        return;
                    }
                    $orphans[$dir] = $count;
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
                if ($halted) {
                    break;
                }
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

        if ($failures > 0) {
            return self::FAILURE;
        }

        $removeStaging();
        foreach ($orphans as $dir => $count) {
            try {
                if (DB::table('media')->useWritePdo()->where('uuid', $dir)->exists()) {
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

        $this->info("Pruned {$pruned} orphan media directorie(s); {$skipped} recent upload(s) left for the grace window.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
