<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Stocktake;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * جلسات الجرد الفعلي. لقطة system_qty بتتاخد من stock_levels وقت إنشاء العنصر،
 * والاعتماد بيحوّل الفروق لحركات دفتر (فائض/عجز) عبر InventoryService فقط،
 * وبعد الاعتماد الجلسة بتتقفل نهائيًا.
 */
class StocktakeService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return Stocktake::query()
            ->when($filters['staff_branch_id'] ?? null, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['branch', 'location', 'creator'])
            ->withCount('items')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): Stocktake
    {
        return Stocktake::query()
            ->with(['items.variant', 'branch', 'location', 'creator', 'approver'])
            ->findOrFail($id);
    }

    public function create(array $data, int $userId): Stocktake
    {
        return DB::transaction(function () use ($data, $userId): Stocktake {
            app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);
            $stocktake = Stocktake::query()->create([
                'stocktake_number' => $this->settings->generateNextNumber('stocktake'),
                'branch_id' => $data['branch_id'],
                'location_id' => $data['location_id'] ?? null,
                'status' => StocktakeStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($stocktake, $data['items'] ?? []);

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    public function update(Stocktake $stocktake, array $data, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $data, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);
            app(BranchAccess::class)->assertBranch($userId, (int) ($data['branch_id'] ?? $stocktake->branch_id));

            $branchId = $data['branch_id'] ?? $stocktake->branch_id;
            $locationId = array_key_exists('location_id', $data) ? $data['location_id'] : $stocktake->location_id;
            if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('branch_id', $branchId)->exists()) {
                throw new BusinessException('موقع الجرد لا يتبع الفرع المحدد.');
            }
            if (((int) $branchId !== $stocktake->branch_id || ($locationId === null ? null : (int) $locationId) !== $stocktake->location_id)
                && ! array_key_exists('items', $data) && $stocktake->items()->exists()) {
                throw new BusinessException('تغيير نطاق الجرد يتطلب إعادة إرسال العناصر والعد الفعلي.');
            }

            if (isset($data['status'])) {
                $target = StocktakeStatus::from($data['status']);
                $allowed = match ($stocktake->status) {
                    StocktakeStatus::Draft => [StocktakeStatus::InProgress, StocktakeStatus::Cancelled],
                    StocktakeStatus::InProgress => [StocktakeStatus::Cancelled],
                    default => [],
                };

                if (! in_array($target, $allowed, true)) {
                    throw new BusinessException("لا يمكن نقل الجرد من حالة {$stocktake->status->value} إلى {$target->value}.");
                }

                $stocktake->status = $target;
            }

            $stocktake->fill([
                'branch_id' => $data['branch_id'] ?? $stocktake->branch_id,
                'location_id' => array_key_exists('location_id', $data) ? $data['location_id'] : $stocktake->location_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $stocktake->notes,
            ])->save();

            if (isset($data['items'])) {
                $stocktake->items()->delete();
                $this->syncItems($stocktake, $data['items']);
            }

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * الاعتماد: تحويل كل فارق غير صفري لحركة دفتر (stocktake_gain/stocktake_loss)
     * على on_hand في نطاق الجلسة (فرعها وموقعها)، ثم قفل الجلسة.
     */
    public function approve(Stocktake $stocktake, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);
            $stocktake->load('items.variant');

            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
                throw new BusinessException('لا يمكن اعتماد جرد بحالة '.$stocktake->status->value.'.');
            }

            $uncounted = $stocktake->items->filter(fn ($item) => $item->counted_qty === null);

            if ($stocktake->items->isEmpty()) {
                throw new BusinessException('لا يمكن اعتماد جرد بلا عناصر.');
            }

            if ($uncounted->isNotEmpty()) {
                throw new BusinessException('لا يمكن الاعتماد: توجد عناصر لم تُعدّ بعد.');
            }

            $levels = $this->inventory->lockLevels($stocktake->items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
            ])->all());

            // A count becomes stale after a stock change; never overwrite intervening sales/receipts.
            foreach ($stocktake->items as $item) {
                $key = $this->inventory->levelKey([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
                ]);
                if ((int) round((float) $levels[$key]->on_hand * 10000) !== (int) round((float) $item->system_qty * 10000)) {
                    throw new BusinessException('تغير رصيد المخزون منذ آخر عد؛ راجع الكميات وأعد حفظ الجرد قبل الاعتماد.');
                }
                if ((int) round((float) $item->counted_qty * 10000) < (int) round((float) $levels[$key]->reserved * 10000) + (int) round((float) $levels[$key]->damaged * 10000)) {
                    throw new BusinessException('العد الفعلي أقل من المحجوز والتالف؛ راجع الحجوزات وسوِّ التالف قبل إعادة العد والاعتماد.');
                }
            }

            foreach ($stocktake->items as $item) {
                $difference = (float) $item->difference;

                if ($difference === 0.0) {
                    continue;
                }

                $this->inventory->recordMovement([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id,
                    'location_id' => $stocktake->location_id,
                    'type' => $difference > 0 ? StockMovementType::StocktakeGain : StockMovementType::StocktakeLoss,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => $difference,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => Stocktake::class,
                    'reference_id' => $stocktake->id,
                    'created_by' => $userId,
                    'notes' => 'تسوية جرد '.$stocktake->stocktake_number,
                ]);
            }

            $stocktake->update([
                'status' => StocktakeStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $stocktake->load(['items.variant', 'branch', 'location']);
        });
    }

    public function delete(Stocktake $stocktake, int $userId): void
    {
        DB::transaction(function () use ($stocktake, $userId): void {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف جرد إلا في حالة المسودة أو الملغي.');
            }

            $stocktake->delete();
        });
    }

    private function assertEditable(Stocktake $stocktake): void
    {
        if (in_array($stocktake->status, [StocktakeStatus::Approved, StocktakeStatus::Cancelled], true)) {
            throw new BusinessException('هذا الجرد مقفل ولا يقبل أي تعديل.');
        }
    }

    /**
     * تسجيل العناصر مع لقطة system_qty من الرصيد الحالي في نطاق الجلسة.
     *
     * @param  list<array{product_variant_id: int, counted_qty?: float|int|string|null}>  $items
     */
    private function syncItems(Stocktake $stocktake, array $items): void
    {
        $levels = StockLevel::query()->where('branch_id', $stocktake->branch_id)
            ->where('location_id', $stocktake->location_id)
            ->whereIn('product_variant_id', array_column($items, 'product_variant_id'))
            ->get()->keyBy('product_variant_id');
        foreach ($items as $item) {
            $level = $levels->get($item['product_variant_id']);

            $stocktake->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'system_qty' => $level ? (float) $level->on_hand : 0,
                'counted_qty' => $item['counted_qty'] ?? null,
            ]);
        }
    }
}
