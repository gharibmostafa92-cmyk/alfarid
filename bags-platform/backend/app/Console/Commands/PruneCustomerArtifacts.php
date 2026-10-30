<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneCustomerArtifacts extends Command
{
    protected $signature = 'customers:prune-artifacts';

    protected $description = 'Remove abandoned private staging files, expired phone challenges and old message counters';

    public function handle(): int
    {
        $failures = 0;
        $disk = Storage::disk('r2');

        // M11: isolate each stage — an R2 exception in the storage sweep must
        // not cancel the DB cleanups below. Customer existence is resolved in
        // batches of 500 instead of one exists() per directory.
        try {
            foreach ($disk->directories('customer_documents/pending') as $directory) {
                if (! preg_match('~^customer_documents/pending/[a-f0-9-]{36}$~D', $directory)) {
                    continue;
                }
                try {
                    $newest = 0;
                    foreach ($disk->allFiles($directory) as $file) {
                        $newest = max($newest, $disk->lastModified($file));
                    }
                    if ($newest < now()->subHour()->timestamp) {
                        $disk->deleteDirectory($directory);
                    }
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);
                    $this->error('Could not process '.$directory.'; rerun after checking storage access.');
                }
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
                try {
                    $newest = 0;
                    foreach ($disk->allFiles($directory) as $file) {
                        $newest = max($newest, $disk->lastModified($file));
                    }
                    if ($newest < now()->subHour()->timestamp) {
                        $disk->deleteDirectory($directory);
                    }
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);
                    $this->error('Could not process '.$directory.'; rerun after checking storage access.');
                }
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
}
