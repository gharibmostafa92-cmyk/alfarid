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
 * توزيع الطلب على الفروع وحجز مخزونه (reserved) عبر InventoryService حصرًا.
 *
 * الخوارزمية:
 * 1. فرع مُجبر من الأدمن: يُتحقق من تغطيته ويُحجز منه.
 * 2. أول فرع نشط واحد يغطي الطلب كاملًا — أولوية لفرع نفس مدينة العميل.
 * 3. وإلا: كل صنف من الفرع الأعلى رصيدًا متاحًا له (الصنف كاملًا من فرع واحد).
 *
 * داخل الفرع الواحد قد يتوزع الصنف على أكثر من موقع (الأعلى متاحًا أولًا).
 * أي نقص في أي صنف يُلغي العملية كلها (transaction واحدة — لا حجز جزئي يتسرب).
 */
class OrderAllocationService
{
    public function __construct(protected InventoryService $inventoryService) {}

    public function allocate(Order $order, ?int $forcedBranchId = null, ?int $performedBy = null): Order
    {
        return DB::transaction(function () use ($order, $forcedBranchId, $performedBy): Order {
            // إعادة قراءة الطلب بقفل صف قبل فحص التوزيع السابق (C1)
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->load('items');
            // ترتيب قفل عام ثابت (F-02): البنود بترتيب product_variant_id
            // في كل المسارات حتى لا ينتظر طلبان بعضهما دائريًا (40P01)
            $order->setRelation('items', $order->items
                ->sortBy(fn (OrderItem $item): int => (int) $item->product_variant_id)
                ->values());
            $performedBy ??= auth()->id();

            if ($order->allocated_branch_id !== null
                || $order->items->contains(fn (OrderItem $item): bool => $item->allocated_branch_id !== null)) {
                throw new BusinessException('الطلب مُوزّع على الفروع من قبل؛ ألغِ التوزيع الحالي أولًا قبل إعادة التوزيع.');
            }

            // 1. فرع مُجبر يدويًا
            if ($forcedBranchId !== null) {
                $branch = Branch::query()->where('is_active', true)->find($forcedBranchId);
                if ($branch === null) {
                    throw new BusinessException('الفرع المحدد غير موجود أو غير نشط.');
                }
                $this->reserveItemsFromBranch($order, $branch->id, $performedBy);
                $order->update(['allocated_branch_id' => $branch->id]);

                return $order->fresh(['items']);
            }

            // خريطة تغطية واحدة مجمّعة لكل الفروع والأصناف (M15) بدل
            // استعلام لكل فرع × صنف — القرارات التالية كلها في الذاكرة
            $coverage = $this->branchAvailabilityMap($order);

            // 2. فرع واحد يغطي الطلب كاملًا (أولوية لنفس مدينة العميل:
            // بالمعرّف أولًا ثم بمطابقة الاسم كرجوع — L5)
            $cityName = $order->city?->name_ar;
            $branches = Branch::query()
                ->where('is_active', true)
                ->when($order->city_id !== null, fn (Builder $q): Builder => $q->orderByRaw('CASE WHEN city_id = ? THEN 0 ELSE 1 END', [$order->city_id]))
                // CASE لا «city = ? DESC» (F17): في Postgres ترتيب DESC يضع
                // NULL أولًا فتتقدّم فروع بلا مدينة معلنة على فرع مدينة العميل
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
                $plan = [];
                foreach ($order->items as $item) {
                    $branchId = $this->bestBranchForItem($item, $coverage);
                    if ($branchId === null) {
                        throw new BusinessException("الرصيد المتاح للصنف [{$item->sku_snapshot}] غير كافٍ لإتمام الطلب.");
                    }
                    $plan[(int) $item->product_variant_id] = $branchId;
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
     * فك توزيع الطلب (M5): يُحرّر كل حجوزات الطلب من وعاء reserved ويعيده
     * إلى «غير موزّع» — كان endpoint التوزيع يشير لهذه العملية في رسالته
     * بينما لا سبيل لتنفيذها. الحجوزات تُقرأ من دفتر الحركات نفسه، فلا يعتمد
     * الفك على حالة مشتقة قابلة للانحراف.
     */
    public function deallocate(Order $order, ?int $performedBy = null): Order
    {
        return DB::transaction(function () use ($order, $performedBy): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->load('items');
            // ترتيب قفل عام ثابت كالتوزيع (F-02) لتفادي الجمود
            $order->setRelation('items', $order->items
                ->sortBy(fn (OrderItem $item): int => (int) $item->product_variant_id)
                ->values());
            $performedBy ??= auth()->id();

            if (! $this->isAllocated($order)) {
                throw new BusinessException('الطلب غير موزّع على أي فرع؛ لا شيء لفكه.');
            }

            // C3: الحجز القائم الصافي لكل مفتاح — لا يُفك أي حجز مرتين
            // مهما تكرر allocate/deallocate.
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

    /** هل الطلب موزّع على أي فرع (على مستوى الطلب أو أي بند)؟ */
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

    /**
     * تغطية الفروع لأصناف الطلب باستعلام واحد مجمّع (M15):
     * ‏[branch_id][variant_id] => مجموع المتاح في المواقع النشطة.
     *
     * @return array<int, array<int, float>>
     */
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

    /** @param array<int, array<int, float>> $coverage */
    private function canBranchFulfillFullOrder(Order $order, int $branchId, array $coverage): bool
    {
        foreach ($order->items as $item) {
            if (($coverage[$branchId][(int) $item->product_variant_id] ?? 0) < (float) $item->qty) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<int, float>> $coverage */
    private function bestBranchForItem(OrderItem $item, array $coverage): ?int
    {
        $best = null;
        $bestAvailable = 0.0;
        foreach ($coverage as $branchId => $variants) {
            $available = $variants[(int) $item->product_variant_id] ?? 0;
            if ($available >= (float) $item->qty && $available > $bestAvailable) {
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

    /**
     * حجز كمية الصنف من فرع واحد، موزعة على مواقعه الأعلى متاحًا عند الحاجة.
     */
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

    /**
     * صفوف رصيد قابلة للاستخدام: رصيد الفرع/المتغير في مواقع نشطة
     * (أو بلا موقع) — الحجز لا يخرج من موقع معطّل.
     *
     * @return Builder<StockLevel>
     */
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
