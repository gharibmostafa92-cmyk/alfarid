<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class RetailPriceSnapshot
{
    public function refresh(int|array|null $productId = null): void
    {
        if ($productId === []) {
            return;
        }

        Product::query()->when($productId !== null, fn ($query) => $query->whereKey($productId))
            ->when($productId === null, fn ($query) => $query->where('status', 'published')->whereIn('sales_channel', ['retail', 'both']))
            ->select('id')->chunkById(200, function ($products): void {
                $ids = array_map('intval', $products->modelKeys());
                sort($ids);

                DB::transaction(function () use ($ids): void {
                    // Serialize concurrent snapshot writers (e.g. VariantService vs RefreshCatalogRetailPrices)
                    // in deterministic ID order without blocking reads or order foreign keys.
                    DB::table('products')->whereIn('id', $ids)->orderBy('id')->lock('for no key update')->pluck('id');
                    $catalog = app(StoreCatalogService::class);
                    $query = $catalog->liveMinimumPriceQuery(null);
                    $discount = $catalog->liveRetailDiscountQuery();
                    DB::update('UPDATE products SET catalog_min_retail_price = ('.$query->toSql().'), catalog_has_retail_discount = COALESCE(('.$discount->toSql().'), false), catalog_price_refreshed_at = ? WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                        [...$query->getBindings(), ...$discount->getBindings(), now(), ...$ids]);
                });
            });
    }
}
