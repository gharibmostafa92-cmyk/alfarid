<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Pos\PosService;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Monitors price list, variant, and stock mutations to batch-refresh catalog price snapshots across nested transactions.
 */
class CatalogRetailPriceObserver
{
    private int $batchDepth = 0;

    private array $pendingProductIds = [];

    private array $transactionSnapshots = [];

    /**
     * Records pre-transaction state snapshot on connection begin.
     *
     * @param  Connection  $connection  Database connection instance
     */
    public function transactionBegan(Connection $connection): void
    {
        if ($this->batchDepth > 0 && $connection->getName() === config('database.default')) {
            $this->transactionSnapshots[$connection->transactionLevel()] = $this->pendingProductIds;
        }
    }

    /**
     * Reverts pending IDs if transaction rolled back, or cleans up state on commit.
     *
     * @param  Connection  $connection  Database connection
     * @param  bool  $rolledBack  Whether transaction rolled back
     */
    public function transactionFinished(Connection $connection, bool $rolledBack = false): void
    {
        if ($this->batchDepth === 0 || $connection->getName() !== config('database.default')) {
            return;
        }
        $level = $connection->transactionLevel();
        if ($rolledBack && isset($this->transactionSnapshots[$level + 1])) {
            $this->pendingProductIds = $this->transactionSnapshots[$level + 1];
        }
        foreach (array_keys($this->transactionSnapshots) as $snapshotLevel) {
            if ($snapshotLevel > $level) {
                unset($this->transactionSnapshots[$snapshotLevel]);
            }
        }
    }

    /**
     * Groups mutations in a closure to defer dispatching refresh job until outermost batch completes.
     *
     * @param  callable  $callback  Executable closure
     * @return mixed Closure return value
     */
    public function batch(callable $callback): mixed
    {
        $previous = $this->pendingProductIds;
        $this->batchDepth++;
        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $this->pendingProductIds = $previous;
            throw $exception;
        } finally {
            $this->batchDepth--;
            if ($this->batchDepth === 0) {
                $this->transactionSnapshots = [];
            }
        }

        if ($this->batchDepth === 0 && $this->pendingProductIds !== []) {
            $ids = $this->pendingProductIds;
            $this->pendingProductIds = [];
            $this->dispatchRefresh($ids);
        }

        return $result;
    }

    public function saved(Model $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model, true);
    }

    public function restored(Model $model): void
    {
        $this->refresh($model, true);
    }

    private function refresh(Model $model, bool $force = false): void
    {
        if ($model instanceof StockLevel) {
            PosService::invalidateCatalogVersionCache((int) $model->branch_id, stockOnly: false);
        } else {
            PosService::invalidateCatalogVersionCache(null);
        }

        if ($model instanceof Category) {
            // Categories only affect the POS catalog version; the price
            // snapshot comes from variants, price lists and stock.
            return;
        }

        $productIds = [];
        if ($model instanceof Product) {
            // Snapshot columns are written by the refresh job itself; only
            // publishability/category changes warrant another refresh.
            if (! $force && ! $model->wasRecentlyCreated && ! $model->wasChanged(['status', 'sales_channel', 'category_id'])) {
                return;
            }
            $productIds = [$model->id];
        } elseif ($model instanceof PriceList) {
            // Header changes (active window, priority, branch) alter every
            // product priced by this list.
            $variantIds = PriceListItem::query()
                ->where('price_list_id', $model->id)
                ->pluck('product_variant_id')
                ->all();
            $productIds = ProductVariant::withTrashed()->whereIn('id', $variantIds)->pluck('product_id')->all();
        } elseif ($model instanceof ProductVariant) {
            $productIds = [$model->product_id, $model->getRawOriginal('product_id')];
        } elseif ($model instanceof PriceListItem || $model instanceof StockLevel) {
            $variantIds = [$model->product_variant_id, $model->getRawOriginal('product_variant_id')];
            $loadedVariant = $model->relationLoaded('variant') ? $model->getRelation('variant') : null;
            if (! $model->wasChanged('product_variant_id') && $loadedVariant?->id === $model->product_variant_id && $loadedVariant->product_id !== null) {
                $productIds = [$loadedVariant->product_id];
            } else {
                $productIds = ProductVariant::withTrashed()->whereIn('id', array_filter($variantIds))->pluck('product_id')->all();
            }
        } else {
            $this->dispatchRefresh();

            return;
        }

        $clean = array_values(array_filter(array_unique(array_map('intval', $productIds))));
        if ($clean === []) {
            return;
        }

        if ($this->batchDepth > 0) {
            $this->pendingProductIds = array_values(array_unique(array_merge($this->pendingProductIds, $clean)));
        } else {
            $this->dispatchRefresh($clean);
        }
    }

    private function dispatchRefresh(int|array|null $productIds = null): void
    {
        // PendingDispatch acquires the unique lock before the job's afterCommit
        // setting takes effect. Defer dispatch itself so a database cache lock
        // collision cannot abort the transaction saving the model.
        DB::afterCommit(static function () use ($productIds): void {
            RefreshCatalogRetailPrices::dispatch($productIds);
        });
    }
}
