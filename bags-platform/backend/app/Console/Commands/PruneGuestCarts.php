<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

/**
 * Prunes abandoned guest carts (customer_id = null) older than the retention window.
 * Deletion is batched (1000 per chunk); cart items are purged via database foreign key cascade.
 */
class PruneGuestCarts extends Command
{
    protected $signature = 'carts:prune-guests {--days=7 : Retention window in days}';

    protected $description = 'Delete abandoned guest carts older than the retention window';

    /**
     * Executes the guest carts deletion routine.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = Cart::query()
                ->whereNull('customer_id')
                ->where('updated_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Cart::query()->whereIn('id', $ids)->delete();
        } while (true);

        $this->info("Pruned {$deleted} guest carts older than {$days} days.");

        return self::SUCCESS;
    }
}
