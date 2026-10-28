<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\RetailPriceSnapshot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued unique job refreshing denormalized catalog minimum retail prices and stock availability snapshots.
 */
class RefreshCatalogRetailPrices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int|array|null $productId = null)
    {
        $this->afterCommit();
    }

    /**
     * Resolves the cache lock key ensuring deduplication while job is queued or executing.
     *
     * @return string Unique job identifier
     */
    public function uniqueId(): string
    {
        if (is_array($this->productId)) {
            $ids = array_unique($this->productId);
            sort($ids);

            return 'catalog-retail:batch:'.hash('sha256', json_encode($ids));
        }

        return 'catalog-retail:'.($this->productId ?? 'all');
    }

    /**
     * Executes the snapshot refresh calculation.
     *
     * @param RetailPriceSnapshot $snapshots Snapshot calculation service
     */
    public function handle(RetailPriceSnapshot $snapshots): void
    {
        $snapshots->refresh($this->productId);
    }
}
