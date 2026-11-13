<?php

declare(strict_types=1);

namespace App\Console\Commands\Pos;

use App\Models\PosHeldSale;
use Illuminate\Console\Command;

/**
 * Removes abandoned parked POS carts so held rows cannot accumulate forever.
 */
class PruneHeldSales extends Command
{
    protected $signature = 'pos:prune-held-sales {--days=7 : Age in days before a held sale is considered stale}';

    protected $description = 'Delete stale parked POS carts older than the configured retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = 0;
        do {
            // Fetch batch IDs excluding actively leased carts, safe for PostgreSQL syntax
            $ids = PosHeldSale::query()
                ->where('created_at', '<', $cutoff)
                ->where(function ($q): void {
                    $q->whereNull('resumed_at')
                        ->orWhere('resumed_at', '<', now()->subMinutes(10));
                })
                ->orderBy('id')
                ->limit(500)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                break;
            }

            $count = PosHeldSale::query()->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while (count($ids) === 500);

        $this->info("Pruned {$deleted} held sale(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
