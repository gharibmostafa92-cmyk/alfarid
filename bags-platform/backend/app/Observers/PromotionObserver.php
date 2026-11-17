<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Promotion;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Support\Facades\DB;

/** Keeps storefront price snapshots and public catalog caches aligned with promotion rules. */
class PromotionObserver
{
    public function created(Promotion $promotion): void
    {
        $this->refreshStorefront();
    }

    public function updated(Promotion $promotion): void
    {
        $this->refreshStorefront();
    }

    public function deleted(Promotion $promotion): void
    {
        $this->refreshStorefront();
    }

    private function refreshStorefront(): void
    {
        DB::afterCommit(static function (): void {
            StoreCatalogService::forgetStorefrontCaches();
            RefreshCatalogRetailPrices::dispatch();
        });
    }
}
