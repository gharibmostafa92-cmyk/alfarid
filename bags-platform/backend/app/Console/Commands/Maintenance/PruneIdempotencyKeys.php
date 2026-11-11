<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\InventoryIdempotencyKey;
use Illuminate\Console\Command;

/**
 * Prunes expired inventory idempotency keys.
 * Keys protect retry windows during transient network failures; keeping them beyond 7 days causes table bloat.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'inventory:prune-idempotency-keys';

    protected $description = 'Delete inventory idempotency keys older than 7 days';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(7);
        do {
            $ids = InventoryIdempotencyKey::query()->where('created_at', '<', $cutoff)
                ->orderBy('created_at')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += InventoryIdempotencyKey::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("تم حذف {$deleted} مفتاح عدم تكرار قديم.");

        return self::SUCCESS;
    }
}
