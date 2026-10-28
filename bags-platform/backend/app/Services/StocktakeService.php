<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Concerns\HandlesIdempotentCreation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages physical inventory stocktaking sessions, count reconciliation, and ledger adjustments.
 */
class StocktakeService
{
    use HandlesIdempotentCreation;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    /**
     * Paginates stocktake sessions with applied branch and status filters.
     *
     * @param array<string, mixed> $filters Query filters
     * @return LengthAwarePaginator Paginated stocktakes
     */
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

    /**
     * Retrieves a single stocktake session with loaded associations.
     *
     * @param int $id Stocktake ID
     * @return Stocktake Retrieved stocktake model
     */
    public function find(int $id): Stocktake
    {
        return Stocktake::query()
            ->with(['items.variant', 'branch', 'location', 'creator', 'approver'])
            ->findOrFail($id);
    }

    /**
     * Creates a new draft stocktake session idempotently.
     *
     * @param array<string, mixed> $data Stocktake attributes and items
     * @param int $userId Creator ID
     * @param string|null $idempotencyKey Optional client request idempotency key
     * @return Stocktake Created stocktake
     * @throws BusinessException If location or branch scope is invalid
     */
    public function create(array $data, int $userId, ?string $idempotencyKey = null): Stocktake
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);

        /** @var Stocktake */
        return $this->createIdempotently(
            'stocktake_store',
            $data,
            $userId,
            $idempotencyKey,
            Stocktake::class,
            ['items.variant', 'branch', 'location', 'creator'],
            fn (): Stocktake => $this->doCreate($data, $userId),
        );
    }

    private function doCreate(array $data, int $userId): Stocktake
    {
        return DB::transaction(function () use ($data, $userId): Stocktake {
            app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);
            $this->assertScopedLocation((int) $data['branch_id'], $data['location_id'] ?? null);
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

    /**
     * Updates an editable stocktake session attributes, status, and items.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param array<string, mixed> $data Updated attributes
     * @param int $userId User performing update
     * @return Stocktake Updated stocktake instance
     * @throws BusinessException If stocktake is locked or transition is invalid
     */
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
            if ($locationId === null && ($data['status'] ?? null) !== StocktakeStatus::Cancelled->value) {
                $this->assertScopedLocation((int) $branchId, null);
            }
            $scopeChanged = (int) $branchId !== $stocktake->branch_id || ($locationId === null ? null : (int) $locationId) !== $stocktake->location_id;
            if ($scopeChanged
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
                if ($scopeChanged) {
                    $data['items'] = array_map(fn ($item) => [...$item, 'recount' => true], $data['items']);
                }
                $this->upsertItems($stocktake, $data['items']);
            }

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * Approves a stocktake session, recording inventory adjustments for non-zero count differences.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param int $userId Approving user ID
     * @return Stocktake Approved and locked stocktake
     * @throws BusinessException If items are uncounted, stale, or stocktake cannot be approved
     */
    public function approve(Stocktake $stocktake, int $userId): Stocktake
    {
        return app(CatalogRetailPriceObserver::class)->batch(fn () => DB::transaction(function () use ($stocktake, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);
            $stocktake->load('items.variant');

            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
                throw new BusinessException('لا يمكن اعتماد جرد بحالة '.$stocktake->status->value.'.');
            }

            $this->assertScopedLocation((int) $stocktake->branch_id, $stocktake->location_id);
            $this->assertItemLimit($stocktake->items->count());

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

            // Reconcile according to configured stale policy: reject on intermediate movement or apply net difference.
            $rejectStale = config('inventory.stocktake_stale_policy', 'reject') !== 'apply_difference';
            $staleItemIds = [];
            $staleVariantIds = [];
            $latestMovements = StockMovement::query()->where('branch_id', $stocktake->branch_id)
                ->where('location_id', $stocktake->location_id)->where('affected_bucket', StockBucket::OnHand->value)
                ->whereIn('product_variant_id', $stocktake->items->pluck('product_variant_id'))
                ->when(! $rejectStale, fn ($q) => $q->whereIn('type', [StockMovementType::StocktakeGain->value, StockMovementType::StocktakeLoss->value]))
                ->selectRaw('product_variant_id, MAX(id) AS last_id')->groupBy('product_variant_id')
                ->pluck('last_id', 'product_variant_id');
            foreach ($stocktake->items as $item) {
                $key = $this->inventory->levelKey([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
                ]);
                if ((int) ($latestMovements[$item->product_variant_id] ?? 0) > (int) $item->snapshot_movement_id
                    || ($rejectStale && (int) round((float) $levels[$key]->on_hand * 10000) !== (int) round((float) $item->system_qty * 10000))) {
                    $staleItemIds[] = $item->id;
                    $staleVariantIds[] = $item->product_variant_id;
                }
                $finalQty = (float) $levels[$key]->on_hand + (float) $item->difference;
                if ((int) round($finalQty * 10000) < (int) round((float) $levels[$key]->reserved * 10000) + (int) round((float) $levels[$key]->damaged * 10000)) {
                    throw new BusinessException('العد الفعلي أقل من المحجوز والتالف؛ راجع الحجوزات وسوِّ التالف قبل إعادة العد والاعتماد.');
                }
            }

            if ($staleItemIds !== []) {
                throw new BusinessException(
                    'توجد أصناف تغيّر رصيدها بعد عدّها وتحتاج إعادة عد قبل الاعتماد.',
                    ['recount_item_ids' => $staleItemIds, 'recount_variant_ids' => $staleVariantIds],
                );
            }

            foreach ($stocktake->items as $item) {
                $difference = (float) $item->difference;

                if ($difference === 0.0) {
                    continue;
                }

                $key = $this->inventory->levelKey([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
                ]);

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
                ], $levels[$key] ?? null);
            }

            $stocktake->update([
                'status' => StocktakeStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $stocktake->load(['items.variant', 'branch', 'location']);
        }));
    }

    /**
     * Deletes a draft or cancelled stocktake session.
     *
     * @param Stocktake $stocktake Stocktake to delete
     * @param int $userId Requesting user ID
     * @throws BusinessException If session is in an undeletable status
     */
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

    private function assertScopedLocation(int $branchId, mixed $locationId): void
    {
        if ($locationId !== null) {
            return;
        }

        // Prevent branch-wide unlocated stocktaking when location-specific stock exists to avoid artificial discrepancies.
        $hasLocatedStock = StockLevel::query()->where('branch_id', $branchId)
            ->whereNotNull('location_id')->hasNonZeroBalance()->exists();

        if ($hasLocatedStock) {
            throw new BusinessException('هذا الفرع يستخدم المواقع وله أرصدة عليها؛ حدد موقع الجرد بدل العدّ الإجمالي للفرع.');
        }
    }

    private function assertEditable(Stocktake $stocktake): void
    {
        if (in_array($stocktake->status, [StocktakeStatus::Approved, StocktakeStatus::Cancelled], true)) {
            throw new BusinessException('هذا الجرد مقفل ولا يقبل أي تعديل.');
        }
    }

    /**
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null}> $items
     */
    private function syncItems(Stocktake $stocktake, array $items): void
    {
        $this->assertItemLimit(count($items));
        $levels = $this->snapshotLevels($stocktake, $items);
        $snapshotMovementId = (int) StockMovement::query()->max('id');
        foreach ($items as $item) {
            $level = $levels->get($item['product_variant_id']);

            $stocktake->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'system_qty' => $level ? (float) $level->on_hand : 0,
                'snapshot_movement_id' => $snapshotMovementId,
                'counted_qty' => $item['counted_qty'] ?? null,
            ]);
        }
    }

    /**
     * Incrementally updates a subset of stocktake items without deleting unmentioned ones.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null, recount?: bool|null}> $items Items payload
     * @param int $userId User ID
     * @return Stocktake Updated stocktake
     */
    public function upsertSomeItems(Stocktake $stocktake, array $items, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $items, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            $this->upsertItems($stocktake, $items, deleteAbsent: false);
            $stocktake->touch();

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * Adds counted quantity to a stocktake item under row lock (e.g. for barcode scanning).
     *
     * @param Stocktake $stocktake Target stocktake
     * @param int $variantId Scanned variant ID
     * @param float $quantity Scanned quantity increment
     * @param int $userId Scanning user ID
     * @return Stocktake Updated stocktake
     * @throws BusinessException If session is locked or quantity is invalid
     */
    public function incrementItemCount(Stocktake $stocktake, int $variantId, float $quantity, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $variantId, $quantity, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            $existing = $stocktake->items()->where('product_variant_id', $variantId)->first();
            $counted = (float) ($existing?->counted_qty ?? 0);
            $total = round($counted + $quantity, 4);
            if (! is_finite($quantity) || $quantity <= 0 || $total > 99999999.9999) {
                throw new BusinessException('كمية العد تتجاوز النطاق المسموح.');
            }
            if ($existing !== null && $existing->counted_qty !== null) {
                $existing->update(['counted_qty' => $total]);
            } else {
                $this->upsertItems($stocktake, [['product_variant_id' => $variantId, 'counted_qty' => $total]], deleteAbsent: false);
            }
            $stocktake->touch();

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null, recount?: bool|null}> $items
     */
    private function upsertItems(Stocktake $stocktake, array $items, bool $deleteAbsent = true): void
    {
        $existing = $stocktake->items()->get()->keyBy('product_variant_id');

        $payloadIds = collect($items)->map(fn ($item) => (int) $item['product_variant_id'])->unique()->values()->all();
        $totalItems = $deleteAbsent
            ? count($payloadIds)
            : $existing->count() + count(array_diff($payloadIds, $existing->keys()->map(fn ($id) => (int) $id)->all()));
        $this->assertItemLimit($totalItems);
        $levels = $this->snapshotLevels($stocktake, $items);
        $snapshotMovementId = (int) StockMovement::query()->max('id');

        $keepIds = [];
        foreach ($items as $item) {
            $variantId = (int) $item['product_variant_id'];
            $currentOnHand = $levels->get($variantId) ? (float) $levels->get($variantId)->on_hand : 0.0;
            $row = $existing->get($variantId);

            if ($row === null) {
                $created = $stocktake->items()->create([
                    'product_variant_id' => $variantId,
                    'system_qty' => $currentOnHand,
                    'snapshot_movement_id' => $snapshotMovementId,
                    'counted_qty' => $item['counted_qty'] ?? null,
                ]);
                $keepIds[] = $created->id;

                continue;
            }

            $keepIds[] = $row->id;
            $updates = [];

            if (array_key_exists('counted_qty', $item)) {
                $updates['counted_qty'] = $item['counted_qty'];

                $countChanged = $item['counted_qty'] !== null
                    && ($row->counted_qty === null || (float) $item['counted_qty'] !== (float) $row->counted_qty);

                if ($countChanged || ! empty($item['recount'])) {
                    $updates['system_qty'] = $currentOnHand;
                    $updates['snapshot_movement_id'] = $snapshotMovementId;
                }
            } elseif (! empty($item['recount'])) {
                $updates['system_qty'] = $currentOnHand;
                $updates['snapshot_movement_id'] = $snapshotMovementId;
            }

            if ($updates !== []) {
                $row->update($updates);
            }
        }

        if ($deleteAbsent) {
            $stocktake->items()->whereNotIn('id', $keepIds)->delete();
        }
    }

    private function snapshotLevels(Stocktake $stocktake, array $items): Collection
    {
        return $this->inventory->lockLevels(array_map(fn ($item) => [
            'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
            'product_variant_id' => $item['product_variant_id'],
        ], $items))->keyBy('product_variant_id');
    }

    private function assertItemLimit(int $count): void
    {
        $maxItems = max(1, (int) config('inventory.stocktake_max_items', 2000));
        if ($count > $maxItems) {
            throw new BusinessException("تجاوزت الجلسة الحد الأقصى للبنود ({$maxItems})؛ قسّم الجرد إلى جلسات حسب الموقع.");
        }
    }
}
