<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    protected $signature = 'media:prune-orphans {--disk=r2_public : المجلد العام للكتالوج} {--older-than=60 : عمر المجلد بالدقائق قبل اعتباره يتيمًا} {--dry-run : عرض فقط دون حذف}';

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

        foreach ($disk->directories('') as $dir) {
            try {
                $base = basename($dir);
                // uuid layout only — legacy numeric id/ folders are untouched.
                if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $base)) {
                    continue;
                }
                if (DB::table('media')->where('uuid', $base)->exists()) {
                    continue;
                }
                $files = $disk->allFiles($dir);
                if ($files === []) {
                    $this->warn("Empty directory {$dir} — leaving it in place.");

                    continue;
                }
                $newest = 0;
                foreach ($files as $file) {
                    $newest = max($newest, $disk->lastModified($file));
                }
                if ($newest >= $cutoff) {
                    $skipped++;

                    continue;
                }
                // Recheck after listing timestamps; a live upload may have committed
                // while the storage listing was in progress.
                if (DB::table('media')->where('uuid', $base)->exists()) {
                    continue;
                }
                if ($dryRun) {
                    $this->line("[dry-run] would delete {$dir} (".count($files).' files)');
                } else {
                    if (! $disk->deleteDirectory($dir)) {
                        throw new \RuntimeException('Storage did not confirm deletion.');
                    }
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
