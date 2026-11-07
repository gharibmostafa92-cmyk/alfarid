<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes expired operation idempotency keys beyond the retention window in batches.
 */
class PruneOperationIdempotencyKeys extends Command
{
    protected $signature = 'operations:prune-idempotency-keys {--days=90 : Number of days of idempotency records to retain}';

    protected $description = 'Prune operation idempotency keys older than the retention window';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;
        do {
            $ids = DB::table('operation_idempotency_keys')->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('operation_idempotency_keys')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} expired operation idempotency keys.");

        return self::SUCCESS;
    }
}
