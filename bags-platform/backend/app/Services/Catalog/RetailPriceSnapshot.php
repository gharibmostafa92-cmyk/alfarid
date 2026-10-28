<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\StoreCatalogService;
use Illuminate\Support\Facades\DB;

class RetailPriceSnapshot
{
    public function refresh(int|array|null $productId = null): void
    {
        Product::query()->when($productId !== null, fn ($query) => $query->whereKey($productId))
            ->when($productId === null, fn ($query) => $query->where('status', 'published')->whereIn('sales_channel', ['retail', 'both']))
            ->select('id')->chunkById(200, function ($products): void {
                $query = app(StoreCatalogService::class)->liveMinimumPriceQuery(null);
                $ids = $products->modelKeys();
                DB::update('UPDATE products SET catalog_min_retail_price = ('.$query->toSql().'), catalog_price_refreshed_at = ? WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                    [...$query->getBindings(), now(), ...$ids]);
            });
    }
}
