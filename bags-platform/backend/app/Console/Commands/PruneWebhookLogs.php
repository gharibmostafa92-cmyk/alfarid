<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Command;

/**
 * Prunes expired webhook logs and scrubs processed payloads to preserve idempotency keys while freeing storage.
 */
class PruneWebhookLogs extends Command
{
    protected $signature = 'webhooks:prune-logs {--days=90 : Number of days of webhook records to retain}';

    protected $description = 'Delete webhook log rows older than the retention window';

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
        $scrubbed = 0;

        // Retain 'processed' records to prevent duplicate event replays, but nullify large payloads to reclaim storage space.
        // Keyset cursor pagination prevents repeatedly reprocessing already scrubbed rows.
        $lastScrubbedId = 0;
        do {
            $ids = WebhookLog::query()
                ->where('status', 'processed')
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('payload')
                ->where('id', '>', $lastScrubbedId)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            WebhookLog::query()->whereIn('id', $ids->all())->update(['payload' => null]);
            $scrubbed += $ids->count();
            $lastScrubbedId = $ids->max();
        } while ($ids->count() === 1000);

        // Non-processed statuses carry no deduplication guarantees and are deleted in chunks to avoid table lock escalation.
        $lastDeletedId = 0;
        do {
            $ids = WebhookLog::query()
                ->where('created_at', '<', $cutoff)
                ->where('status', '!=', 'processed')
                ->where('id', '>', $lastDeletedId)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += WebhookLog::query()->whereIn('id', $ids->all())->delete();
            $lastDeletedId = $ids->max();
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} webhook log rows and scrubbed {$scrubbed} processed rows older than {$days} days.");

        return self::SUCCESS;
    }
}
