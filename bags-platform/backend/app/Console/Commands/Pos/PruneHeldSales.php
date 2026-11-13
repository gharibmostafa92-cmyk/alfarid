<?php

declare(strict_types=1);

namespace App\Console\Commands\Pos;

use App\Models\PosHeldSale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
            $count = DB::transaction(function () use ($cutoff): int {
                $ids = PosHeldSale::query()
                    ->where('created_at', '<', $cutoff)
                    ->where(fn ($q) => $q->whereNull('resumed_at')
                        ->orWhere('resumed_at', '<', now()->subMinutes(10)))
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->limit(500)
                    ->lock('for update skip locked')
                    ->pluck('id');

                return PosHeldSale::query()->whereIn('id', $ids)->delete();
            });
            $deleted += $count;
        } while ($count === 500);

        $this->info("Pruned {$deleted} held sale(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
