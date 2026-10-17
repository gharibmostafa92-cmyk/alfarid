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
use Illuminate\Database\Eloquent\Builder;
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
            $order->loadMissing('items');
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

            // 2. فرع واحد يغطي الطلب كاملًا (أولوية لنفس مدينة العميل)
            $cityName = $order->city?->name_ar;
            $branches = Branch::query()
                ->where('is_active', true)
                ->when($cityName !== null, fn (Builder $q): Builder => $q->orderByRaw('city = ? DESC', [$cityName]))
                ->orderBy('id')
                ->get();

            foreach ($branches as $branch) {
                if ($this->canBranchFulfillFullOrder($order, $branch->id)) {
                    $this->reserveItemsFromBranch($order, $branch->id, $performedBy);
                    $order->update(['allocated_branch_id' => $branch->id]);

                    return $order->fresh(['items']);
                }
            }

            // 3. كل صنف من الفرع الأعلى رصيدًا متاحًا له
            foreach ($order->items as $item) {
                $branchId = $this->bestBranchForItem($item);
                if ($branchId === null) {
                    throw new BusinessException("الرصيد المتاح غير كافٍ للصنف ({$item->sku_snapshot}) في أي فرع.");
                }
                $this->reserveItemFromBranch($order, $item, $branchId, $performedBy);
                $item->update(['allocated_branch_id' => $branchId]);
            }

            return $order->fresh(['items']);
        });
    }

    private function canBranchFulfillFullOrder(Order $order, int $branchId): bool
    {
        foreach ($order->items as $item) {
            $available = (float) ($this->usableLevels((int) $item->product_variant_id, $branchId)->sum('available') ?? 0);
            if ($available < (float) $item->qty) {
                return false;
            }
        }

        return true;
    }

    private function bestBranchForItem(OrderItem $item): ?int
    {
        $row = $this->usableLevels((int) $item->product_variant_id)
            ->whereHas('branch', fn (Builder $q): Builder => $q->where('is_active', true))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(available) as total_available')
            ->havingRaw('SUM(available) >= ?', [(float) $item->qty])
            ->orderByDesc('total_available')
            ->first();

        return $row === null ? null : (int) $row->branch_id;
    }

    private function reserveItemsFromBranch(Order $order, int $branchId, ?int $performedBy): void
    {
        foreach ($order->items as $item) {
            $this->reserveItemFromBranch($order, $item, $branchId, $performedBy);
            $item->update(['allocated_branch_id' => $branchId]);
        }
    }

    /**
     * حجز كمية الصنف من فرع واحد، موزعة على مواقعه الأعلى متاحًا عند الحاجة.
     */
    private function reserveItemFromBranch(Order $order, OrderItem $item, int $branchId, ?int $performedBy): void
    {
        $remaining = (float) $item->qty;
        $levels = $this->usableLevels((int) $item->product_variant_id, $branchId)
            ->where('available', '>', 0)
            ->orderByDesc('available')
            ->get();

        foreach ($levels as $level) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $level->available, $remaining);
            $this->inventoryService->recordMovement([
                'product_variant_id' => (int) $item->product_variant_id,
                'branch_id' => $branchId,
                'location_id' => $level->location_id,
                'type' => StockMovementType::Reserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => $take,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $performedBy,
                'notes' => "حجز للطلب رقم {$order->order_number}",
            ]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new BusinessException("الفرع المحدد لا يمتلك كمية كافية من الصنف ({$item->sku_snapshot}).");
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
