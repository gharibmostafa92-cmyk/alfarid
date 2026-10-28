<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allocates and reserves order inventory across branches based on proximity, stock availability, and split policies.
 */
class OrderAllocationService
{
    public function __construct(protected InventoryService $inventoryService) {}

    /**
     * Allocates order items to branch inventory and reserves stock atomically.
     *
     * @param Order $order Placed order to allocate
     * @param int|null $forcedBranchId Manually selected branch by administrator, if any
     * @param int|null $performedBy User ID performing the allocation
     * @return Order Updated order with allocated branches
     * @throws BusinessException If order is in invalid status, already allocated, or insufficient stock
     */
    public function allocate(Order $order, ?int $forcedBranchId = null, ?int $performedBy = null): Order
    {
        return DB::transaction(function () use ($order, $forcedBranchId, $performedBy): Order {
            // Re-read order under row lock to prevent concurrent allocation attempts.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->load('items');
            // Consistent lock order by product_variant_id prevents deadlocks between concurrent allocations.
            $order->setRelation('items', $order->items
                ->sortBy(fn (OrderItem $item): int => (int) $item->product_variant_id)
                ->values());
            $performedBy ??= auth()->id();

            if (in_array($order->status, [\App\Enums\OrderStatus::Cancelled, \App\Enums\OrderStatus::Shipped, \App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Returned], true)) {
                throw new BusinessException('التوزيع غير متاح على هذه الحالة.');
            }

            if ($order->allocated_branch_id !== null
                || $order->items->contains(fn (OrderItem $item): bool => $item->allocated_branch_id !== null)) {
                throw new BusinessException('الطلب مُوزّع على الفروع من قبل؛ ألغِ التوزيع الحالي أولًا قبل إعادة التوزيع.');
            }

            if ($forcedBranchId !== null) {
                $branch = Branch::query()->where('is_active', true)->find($forcedBranchId);
                if ($branch === null) {
                    throw new BusinessException('الفرع المحدد غير موجود أو غير نشط.');
                }
                $this->reserveItemsFromBranch($order, $branch->id, $performedBy);
                $order->update(['allocated_branch_id' => $branch->id]);

                return $order->fresh(['items']);
            }

            // Pre-calculate aggregate branch availability to evaluate allocation rules in memory.
            $coverage = $this->branchAvailabilityMap($order);

            $cityName = $order->city?->name_ar;
            $branches = Branch::query()
                ->where('is_active', true)
                ->when($order->city_id !== null, fn (Builder $q): Builder => $q->orderByRaw('CASE WHEN city_id = ? THEN 0 ELSE 1 END', [$order->city_id]))
                // Use CASE ordering instead of DESC because Postgres places NULLs first in DESC sorting.
                ->when($cityName !== null, fn (Builder $q): Builder => $q->orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [$cityName]))
                ->orderBy('id')
                ->get();

            $candidateAttempts = 0;
            foreach ($branches as $branch) {
                if ($this->canBranchFulfillFullOrder($order, $branch->id, $coverage)) {
                    if (++$candidateAttempts > 3) {
                        break;
                    }
                    try {
                        return DB::transaction(function () use ($order, $branch, $performedBy): Order {
                            $this->reserveItemsFromBranch($order, $branch->id, $performedBy);
                            $order->update(['allocated_branch_id' => $branch->id]);

                            return $order->fresh(['items']);
                        });
                    } catch (BusinessException $e) {
                        if ($e->errorCode !== 'insufficient_stock') {
                            throw $e;
                        }
                        $order->load('items');
                    }
                }
            }

            // A bounded retry recalculates coverage after a competing checkout.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $coverage = $this->branchAvailabilityMap($order);
                // Aggregate required quantity per variant so duplicate items do not choose an insufficient branch.
                $required = $this->requiredPerVariant($order);
                $plan = [];
                foreach ($order->items as $item) {
                    $variantId = (int) $item->product_variant_id;
                    if (array_key_exists($variantId, $plan)) {
                        continue;
                    }
                    $branchId = $this->bestBranchForItem($item, $coverage, $required[$variantId]);
                    if ($branchId === null) {
                        throw new BusinessException("الرصيد المتاح للصنف [{$item->sku_snapshot}] غير كافٍ لإتمام الطلب.");
                    }
                    $plan[$variantId] = $branchId;
                }
                try {
                    return DB::transaction(function () use ($order, $plan, $performedBy): Order {
                        $levels = $this->lockPlannedLevels($plan);
                        foreach ($order->items as $item) {
                            $branchId = $plan[(int) $item->product_variant_id];
                            $this->reserveItemFromBranch($order, $item, $branchId, $performedBy, $levels);
                            $item->update(['allocated_branch_id' => $branchId]);
                        }

                        return $order->fresh(['items']);
                    });
                } catch (BusinessException $e) {
                    if ($e->errorCode !== 'insufficient_stock' || $attempt === 2) {
                        throw $e;
                    }
                    $order->load('items');
                }
            }
            throw new BusinessException('تغير المخزون أثناء الحجز؛ أعد المحاولة.', [], 409);
        });
    }

    /**
     * Releases all inventory reservations tied to an allocated order and clears allocation state.
     *
     * @param Order $order Order to deallocate
     * @param int|null $performedBy User ID performing the action
     * @return Order Deallocated order instance
     * @throws BusinessException If order cannot be deallocated
     */
    public function deallocate(Order $order, ?int $performedBy = null): Order
    {
        return DB::transaction(function () use ($order, $performedBy): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->load('items');
            // Consistent lock order by product_variant_id prevents deadlocks between concurrent allocations.
            $order->setRelation('items', $order->items
                ->sortBy(fn (OrderItem $item): int => (int) $item->product_variant_id)
                ->values());
            $performedBy ??= auth()->id();

            if (in_array($order->status, [\App\Enums\OrderStatus::Cancelled, \App\Enums\OrderStatus::Shipped, \App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Returned], true)) {
                throw new BusinessException('فك التوزيع غير متاح على هذه الحالة.');
            }

            if (! $this->isAllocated($order)) {
                throw new BusinessException('الطلب غير موزّع على أي فرع؛ لا شيء لفكه.');
            }

            // Calculate net reservations from ledger movements so deallocations remain idempotent.
            $net = StockMovement::query()
                ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
                ->where('reference_id', $order->id)
                ->whereIn('type', [StockMovementType::Reserve->value, StockMovementType::ReleaseReserve->value])
                ->groupBy('product_variant_id', 'branch_id', 'location_id')
                ->selectRaw('product_variant_id, branch_id, location_id, SUM(quantity) AS net_qty')
                ->get()
                ->map(fn ($row): array => [
                    'product_variant_id' => (int) $row->product_variant_id,
                    'branch_id' => (int) $row->branch_id,
                    'location_id' => $row->location_id === null ? null : (int) $row->location_id,
                    'net_qty' => (float) $row->net_qty,
                ])
                ->filter(fn (array $row): bool => $row['net_qty'] > 1e-9)
                ->sortBy(fn (array $row): string => $row['branch_id'].':'.$row['location_id'].':'.$row['product_variant_id'])
                ->values();

            $levels = $this->inventoryService->lockLevels($net->map(fn (array $row): array => [
                'product_variant_id' => $row['product_variant_id'],
                'branch_id' => $row['branch_id'],
                'location_id' => $row['location_id'],
            ])->all());

            foreach ($net as $row) {
                $this->inventoryService->recordMovement([
                    'product_variant_id' => (int) $row['product_variant_id'],
                    'branch_id' => (int) $row['branch_id'],
                    'location_id' => $row['location_id'],
                    'type' => StockMovementType::ReleaseReserve,
                    'affected_bucket' => StockBucket::Reserved,
                    'quantity' => -1 * (float) $row['net_qty'],
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'created_by' => $performedBy,
                    'notes' => "فك يدوي لتوزيع الطلب رقم {$order->order_number}",
                ], $levels->get($this->inventoryService->levelKey($row)));
            }

            $order->items()->update(['allocated_branch_id' => null]);
            $order->update(['allocated_branch_id' => null]);

            return $order->fresh(['items']);
        });
    }

    /**
     * Checks whether an order or any of its items has an allocated branch.
     *
     * @param Order $order Order to check
     * @return bool True if allocated at order level or item level
     */
    public function isAllocated(Order $order): bool
    {
        return $order->allocated_branch_id !== null
            || $order->items->contains(fn (OrderItem $item): bool => $item->allocated_branch_id !== null);
    }

    /** Lock only the branch/variant pairs selected by the coverage plan. */
    private function lockPlannedLevels(array $plan): Collection
    {
        if ($plan === []) {
            return collect();
        }
        $rows = StockLevel::query()->where(function ($query) use ($plan): void {
            foreach ($plan as $variantId => $branchId) {
                $query->orWhere(fn ($q) => $q->where('product_variant_id', $variantId)->where('branch_id', $branchId));
            }
        })->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)->whereNotIn('type', ['receiving', 'damaged'])))->get();

        return $this->inventoryService->lockLevels($rows->map->getAttributes()->all());
    }

    private function branchAvailabilityMap(Order $order): array
    {
        $variantIds = $order->items
            ->map(fn (OrderItem $item): int => (int) $item->product_variant_id)
            ->unique()->values()->all();

        $rows = StockLevel::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereHas('branch', fn (Builder $q): Builder => $q->where('is_active', true))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('location_id')
                ->orWhereHas('location', fn (Builder $l): Builder => $l->where('is_active', true)->whereNotIn('type', ['receiving', 'damaged'])))
            ->groupBy('branch_id', 'product_variant_id')
            ->selectRaw('branch_id, product_variant_id, SUM(available) as total_available')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->branch_id][(int) $row->product_variant_id] = (float) $row->total_available;
        }

        return $map;
    }

    private function requiredPerVariant(Order $order): array
    {
        $required = [];
        foreach ($order->items as $item) {
            $vid = (int) $item->product_variant_id;
            $required[$vid] = ($required[$vid] ?? 0) + (int) round((float) $item->qty * 10000);
        }

        return $required;
    }

    private function canBranchFulfillFullOrder(Order $order, int $branchId, array $coverage): bool
    {
        foreach ($this->requiredPerVariant($order) as $variantId => $qty) {
            if ((int) round(($coverage[$branchId][$variantId] ?? 0) * 10000) < $qty) {
                return false;
            }
        }

        return true;
    }

    private function bestBranchForItem(OrderItem $item, array $coverage, ?int $requiredQty = null): ?int
    {
        $need = $requiredQty ?? (int) round((float) $item->qty * 10000);
        $best = null;
        $bestAvailable = 0.0;
        foreach ($coverage as $branchId => $variants) {
            $available = $variants[(int) $item->product_variant_id] ?? 0;
            if ((int) round($available * 10000) >= $need && $available > $bestAvailable) {
                $best = $branchId;
                $bestAvailable = $available;
            }
        }

        return $best;
    }

    private function reserveItemsFromBranch(Order $order, int $branchId, ?int $performedBy): void
    {
        $levels = $this->lockPlannedLevels($order->items->mapWithKeys(fn ($item) => [(int) $item->product_variant_id => $branchId])->all());
        foreach ($order->items as $item) {
            $this->reserveItemFromBranch($order, $item, $branchId, $performedBy, $levels);
            $item->update(['allocated_branch_id' => $branchId]);
        }
    }

    private function reserveItemFromBranch(Order $order, OrderItem $item, int $branchId, ?int $performedBy, Collection $levels): void
    {
        $remaining = (int) round((float) $item->qty * 10000);
        foreach ($levels->where('product_variant_id', $item->product_variant_id)->where('branch_id', $branchId)->sortByDesc('available') as $level) {
            $take = min((int) round($level->available * 10000), $remaining);
            if ($take <= 0) {
                continue;
            }
            $this->inventoryService->recordMovement([
                'product_variant_id' => (int) $item->product_variant_id, 'branch_id' => $branchId,
                'location_id' => $level->location_id, 'type' => StockMovementType::Reserve,
                'affected_bucket' => StockBucket::Reserved, 'quantity' => $take / 10000,
                'reference_type' => Order::class, 'reference_id' => $order->id,
                'created_by' => $performedBy, 'notes' => 'حجز للطلب '.$order->order_number,
            ], $level);
            $remaining -= $take;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            throw new BusinessException('الرصيد المتاح تغير أثناء الحجز.', errorCode: 'insufficient_stock');
        }
    }

    private function usableLevels(int $variantId, ?int $branchId = null): Builder
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->when($branchId !== null, fn (Builder $q): Builder => $q->where('branch_id', $branchId))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('location_id')
                ->orWhereHas('location', fn (Builder $l): Builder => $l->where('is_active', true)));
    }
}
