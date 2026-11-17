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

    /** @var array<string, array<int, int>> Product IDs queued for one refresh per connection transaction. */
    private array $transactionProductIds = [];

    /** @var array<string, bool> Whether a refresh callback is registered for each connection transaction. */
    private array $transactionRefreshScheduled = [];

    /** @var array<string, bool> Whether the queued refresh must cover the whole catalog. */
    private array $transactionRefreshAll = [];

    /**
     * Records pre-transaction state snapshot on connection begin.
     *
     * @param  Connection  $connection  Database connection instance
     */
    public function transactionBegan(Connection $connection): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();
        $this->transactionSnapshots[$name][$level] = [
            'batch_ids' => $this->pendingProductIds,
            'product_ids' => $this->transactionProductIds[$name] ?? [],
            'refresh_scheduled' => $this->transactionRefreshScheduled[$name] ?? false,
            'refresh_all' => $this->transactionRefreshAll[$name] ?? false,
        ];
    }

    /**
     * Reverts pending IDs if transaction rolled back, or cleans up state on commit.
     *
     * @param  Connection  $connection  Database connection
     * @param  bool  $rolledBack  Whether transaction rolled back
     */
    public function transactionFinished(Connection $connection, bool $rolledBack = false): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();
        if ($rolledBack && isset($this->transactionSnapshots[$name][$level + 1])) {
            $snapshot = $this->transactionSnapshots[$name][$level + 1];
            $this->pendingProductIds = $snapshot['batch_ids'];
            $this->transactionProductIds[$name] = $snapshot['product_ids'];
            $this->transactionRefreshScheduled[$name] = $snapshot['refresh_scheduled'];
            $this->transactionRefreshAll[$name] = $snapshot['refresh_all'];
        }
        foreach (array_keys($this->transactionSnapshots[$name] ?? []) as $snapshotLevel) {
            if ($snapshotLevel > $level) {
                unset($this->transactionSnapshots[$name][$snapshotLevel]);
            }
        }
        if ($rolledBack && $level === 0) {
            unset(
                $this->transactionProductIds[$name],
                $this->transactionRefreshScheduled[$name],
                $this->transactionRefreshAll[$name],
                $this->transactionSnapshots[$name],
            );
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
        $connection = DB::connection();
        $name = $connection->getName();

        if ($connection->transactionLevel() === 0) {
            // PendingDispatch acquires its unique lock only after the callback;
            // cache-lock failures must not abort a model write.
            $connection->afterCommit(static fn () => RefreshCatalogRetailPrices::dispatch($productIds));

            return;
        }

        if ($productIds === null) {
            $this->transactionRefreshAll[$name] = true;
            $this->transactionProductIds[$name] = [];
        } elseif (! ($this->transactionRefreshAll[$name] ?? false)) {
            $this->transactionProductIds[$name] = array_values(array_unique([
                ...($this->transactionProductIds[$name] ?? []),
                ...array_map('intval', (array) $productIds),
            ]));
        }

        if ($this->transactionRefreshScheduled[$name] ?? false) {
            return;
        }

        $this->transactionRefreshScheduled[$name] = true;
        $connection->afterCommit(function () use ($name): void {
            $refreshAll = $this->transactionRefreshAll[$name] ?? false;
            $ids = $this->transactionProductIds[$name] ?? [];
            unset(
                $this->transactionProductIds[$name],
                $this->transactionRefreshScheduled[$name],
                $this->transactionRefreshAll[$name],
            );

            if ($refreshAll || $ids !== []) {
                RefreshCatalogRetailPrices::dispatch($refreshAll ? null : $ids);
            }
        });
    }
}
