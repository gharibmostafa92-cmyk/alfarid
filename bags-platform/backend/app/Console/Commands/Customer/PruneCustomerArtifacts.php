<?php

declare(strict_types=1);

namespace App\Console\Commands\Customer;

use App\Models\Customer;
use App\Support\StorageCleanupOwner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes abandoned private staging files from R2 storage and purges expired phone challenges and audit records.
 */
class PruneCustomerArtifacts extends Command
{
    protected $signature = 'customers:prune-artifacts
        {--deep : Also scan every customer_documents/{id} folder for force-deleted customers (weekly deep pass)}';

    protected $description = 'Remove abandoned private staging files, expired phone challenges and old message counters';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success, 1 on failure).
     */
    public function handle(): int
    {
        $failures = 0;
        $disk = null;
        if (filled(config('filesystems.cleanup_owner'))) {
            try {
                $candidate = Storage::disk('r2');
                StorageCleanupOwner::assertMatches($candidate);
                $disk = $candidate;
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->error('Customer storage cleanup ownership could not be verified.');
            }
        } else {
            // Never let a missing cleanup_owner silently disable cloud pruning:
            // orphaned R2 objects would otherwise accumulate storage cost with
            // zero signal while the command keeps reporting success.
            logger()->warning('Customer storage cleanup disabled: filesystems.cleanup_owner is not set; R2 artifacts will accumulate.');
            $this->warn('Customer storage cleanup is disabled (filesystems.cleanup_owner is not set); skipping R2 artifact pruning.');
        }

        $cutoffTimestamp = now()->subHour()->timestamp;

        // Isolate each cleanup phase so an R2 storage exception does not prevent the database cleanups
        // from executing. Candidate customer existence is resolved in chunks of 500 to avoid N+1 queries.
        try {
            foreach ($disk?->directories('customer_documents/pending') ?? [] as $directory) {
                if (! preg_match('~^customer_documents/pending/[a-f0-9-]{36}$~D', $directory)) {
                    continue;
                }
                $this->pruneDirectoryIfInactive($disk, $directory, $cutoffTimestamp, $failures);
            }
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Pending customer documents listing failed: '.$exception->getMessage());
        }

        // The full customer_documents listing is O(total folders) in cloud
        // Class-A calls: hourly runs clean only the bounded pending prefix
        // and the database tables, while --deep runs weekly for the orphans
        // of force-deleted customers (soft-deleted accounts keep their rows,
        // so their folders are never orphans in the normal lifecycle).
        if ($this->option('deep')) {
            try {
                $candidateIds = [];
                $dirById = [];
                foreach ($disk?->directories('customer_documents') ?? [] as $directory) {
                    if (preg_match('~^customer_documents/([0-9]+)$~D', $directory, $matches)) {
                        $candidateIds[] = (int) $matches[1];
                        $dirById[(int) $matches[1]] = $directory;
                    }
                }
                $existing = [];
                foreach (array_chunk(array_values(array_unique($candidateIds)), 500) as $chunk) {
                    foreach (Customer::withTrashed()->whereIn('id', $chunk)->pluck('id') as $id) {
                        $existing[(int) $id] = true;
                    }
                }
                foreach ($dirById as $customerId => $directory) {
                    if (isset($existing[$customerId])) {
                        continue;
                    }
                    $this->pruneDirectoryIfInactive($disk, $directory, $cutoffTimestamp, $failures);
                }
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->error('Customer documents listing failed: '.$exception->getMessage());
            }
        }

        try {
            DB::table('customer_phone_verifications')->where('expires_at', '<', now()->subHour())->delete();
            DB::table('customer_message_budgets')->where('date', '<', now()->subDays(30)->toDateString())->delete();
            $tokenCutoff = now()->subDay();
            do {
                $deletedTokens = DB::table('personal_access_tokens')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $tokenCutoff)
                    ->limit(1000)
                    ->delete();
            } while ($deletedTokens > 0);
            // F-27: audit retention goes through the SECURITY DEFINER function
            // (mirroring staff audit retention) because the app login no longer
            // holds DELETE on customer_audit_logs.
            $auditCutoff = now()->subDays(90);
            do {
                $removed = (int) DB::scalar('SELECT alfarid_prune_customer_audit_logs(?)', [$auditCutoff], false);
            } while ($removed > 0);
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Customer database artifact cleanup failed: '.$exception->getMessage());
        }

        $this->info('Expired customer artifacts pruned.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Delete storage files if all files within the directory were modified before the cutoff timestamp.
     *
     * Only the exact keys observed by the listing above are deleted — never a
     * blind prefix delete — so an object uploaded between the listing and the
     * delete (TOCTOU) can never be swept away with the stale batch.
     *
     * @param  FilesystemAdapter  $disk  Target storage disk instance
     * @param  string  $directory  Directory path to inspect
     * @param  int  $cutoffTimestamp  Unix cutoff timestamp
     * @param  int  $failures  Failure counter incremented by reference
     */
    private function pruneDirectoryIfInactive(FilesystemAdapter $disk, string $directory, int $cutoffTimestamp, int &$failures): void
    {
        try {
            $newest = 0;
            $files = [];
            foreach ($disk->getDriver()->listContents($directory, true) as $attributes) {
                if (! $attributes->isFile()) {
                    continue;
                }

                $lastModified = $attributes->lastModified();
                if ($lastModified === null) {
                    throw new \RuntimeException('Storage listing did not return file modification metadata.');
                }

                $files[] = $attributes->path();
                $newest = max($newest, $lastModified);
            }
            if ($files !== [] && $newest < $cutoffTimestamp) {
                foreach (array_chunk($files, 500) as $paths) {
                    if (DB::table('customer_documents')->whereIn('file_path', $paths)->exists()) {
                        return;
                    }
                }
                if (! $disk->delete($files)) {
                    throw new \RuntimeException('Storage did not confirm customer document cleanup.');
                }
            }
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Could not process '.$directory.'; rerun after checking storage access.');
        }
    }
}
