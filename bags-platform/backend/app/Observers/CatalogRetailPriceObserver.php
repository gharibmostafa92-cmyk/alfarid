<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Model;

class CatalogRetailPriceObserver
{
    public function saved(Model $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model);
    }

    public function restored(Model $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Model $model): void
    {
        $productIds = [];
        if ($model instanceof ProductVariant) {
            $productIds = [$model->product_id, $model->getRawOriginal('product_id')];
        } elseif ($model instanceof PriceListItem || $model instanceof StockLevel) {
            $variantIds = [$model->product_variant_id, $model->getRawOriginal('product_variant_id')];
            $productIds = ProductVariant::withTrashed()->whereIn('id', array_filter($variantIds))->pluck('product_id')->all();
        } else {
            RefreshCatalogRetailPrices::dispatch();

            return;
        }
        foreach (array_unique(array_filter($productIds)) as $productId) {
            RefreshCatalogRetailPrices::dispatch((int) $productId);
        }
    }
}
