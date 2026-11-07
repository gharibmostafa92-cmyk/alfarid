<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes abandoned private staging files from R2 storage and purges expired phone challenges and audit records.
 */
class PruneCustomerArtifacts extends Command
{
    protected $signature = 'customers:prune-artifacts';

    protected $description = 'Remove abandoned private staging files, expired phone challenges and old message counters';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success, 1 on failure).
     */
    public function handle(): int
    {
        $failures = 0;
        $disk = Storage::disk('r2');

        $cutoffTimestamp = now()->subHour()->timestamp;

        // Isolate each cleanup phase so an R2 storage exception does not prevent the database cleanups
        // from executing. Candidate customer existence is resolved in chunks of 500 to avoid N+1 queries.
        try {
            foreach ($disk->directories('customer_documents/pending') as $directory) {
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

        try {
            $candidateIds = [];
            $dirById = [];
            foreach ($disk->directories('customer_documents') as $directory) {
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

        try {
            DB::table('customer_phone_verifications')->where('expires_at', '<', now()->subHour())->delete();
            DB::table('customer_message_budgets')->where('date', '<', now()->subDays(30)->toDateString())->delete();
            DB::table('customer_audit_logs')->whereIn('action', ['login_failed', 'login_succeeded', 'token_issued'])
                ->where('created_at', '<', now()->subDays(90))
                ->select('id')->chunkById(1000, function ($rows): void {
                    DB::table('customer_audit_logs')->whereIn('id', $rows->pluck('id'))->delete();
                });
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Customer database artifact cleanup failed: '.$exception->getMessage());
        }

        $this->info('Expired customer artifacts pruned.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Delete storage directory if all files within it were modified before the cutoff timestamp.
     *
     * @param  Filesystem  $disk  Target storage disk instance
     * @param  string  $directory  Directory path to inspect
     * @param  int  $cutoffTimestamp  Unix cutoff timestamp
     * @param  int  $failures  Failure counter incremented by reference
     */
    private function pruneDirectoryIfInactive(Filesystem $disk, string $directory, int $cutoffTimestamp, int &$failures): void
    {
        try {
            $newest = 0;
            foreach ($disk->allFiles($directory) as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }
            if ($newest < $cutoffTimestamp) {
                $disk->deleteDirectory($directory);
            }
        } catch (Throwable $exception) {
            $failures++;
            report($exception);
            $this->error('Could not process '.$directory.'; rerun after checking storage access.');
        }
    }
}
