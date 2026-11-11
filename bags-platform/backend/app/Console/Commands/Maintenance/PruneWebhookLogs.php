<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\WebhookLog;
use Illuminate\Console\Command;

/**
 * Prunes expired webhook logs and scrubs processed payloads to preserve idempotency keys while freeing storage.
 */
class PruneWebhookLogs extends Command
{
    protected $signature = 'webhooks:prune-logs {--days=90 : Days to keep webhook payloads} {--dedup-days=365 : Days to retain processed event IDs}';

    protected $description = 'Delete webhook log rows older than the retention window';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dedupDays = max(365, $days, (int) $this->option('dedup-days'));
        $cutoff = now()->subDays($days);
        $dedupCutoff = now()->subDays($dedupDays);
        $deleted = 0;
        $scrubbed = 0;

        // M9: one OR query would defeat the (created_at, id) index and
        // re-scan processed rows on every batch; two single-range deletes
        // each walk the index directly.
        $obsoleteUnprocessed = WebhookLog::query()
            ->where('status', '!=', 'processed')
            ->where('created_at', '<', $cutoff);
        do {
            $ids = (clone $obsoleteUnprocessed)->orderBy('created_at')->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : (clone $obsoleteUnprocessed)->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $obsoleteProcessed = WebhookLog::query()
            ->where('status', 'processed')
            ->where('created_at', '<', $dedupCutoff);
        do {
            $ids = (clone $obsoleteProcessed)->orderBy('created_at')->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : (clone $obsoleteProcessed)->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $expiredPayload = WebhookLog::query()->where('status', 'processed')
            ->where('created_at', '<', $cutoff)->whereNotNull('payload');
        do {
            $ids = (clone $expiredPayload)->orderBy('created_at')->orderBy('id')->limit(1000)->pluck('id');
            $scrubbed += $ids->isEmpty() ? 0 : (clone $expiredPayload)->whereIn('id', $ids)->update(['payload' => null]);
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} webhook rows and scrubbed {$scrubbed} processed payloads. Deduplication retained for {$dedupDays} days.");

        return self::SUCCESS;
    }
}
