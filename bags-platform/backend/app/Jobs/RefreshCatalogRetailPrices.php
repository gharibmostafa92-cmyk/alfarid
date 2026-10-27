<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\RetailPriceSnapshot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshCatalogRetailPrices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public ?int $productId = null)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'catalog-retail:'.($this->productId ?? 'all');
    }

    public function handle(RetailPriceSnapshot $snapshots): void
    {
        $snapshots->refresh($this->productId);
    }
}
