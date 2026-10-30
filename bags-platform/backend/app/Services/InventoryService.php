<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\StockLowDetected;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\InventoryIdempotencyKey;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single authority for modifying stock balances across the platform.
 *
 * Enforces ledger-first accounting: records a stock movement in the ledger
 * before updating row balances under row-level database locks.
 */
class InventoryService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Records an immutable stock movement and updates bucket balances.
     *
     * @param  array{product_variant_id: int, branch_id: int, location_id?: int|null, type: StockMovementType|string, affected_bucket: StockBucket|string, quantity: float|int|string, unit_cost?: float|null, reference_type?: string|null, reference_id?: int|null, created_by?: int|null, notes?: string|null}  $data
     * @throws BusinessException If quantity is invalid, bucket balance goes negative, or available stock is insufficient.
     */
    public function recordMovement(array $data, ?StockLevel $prelockedLevel = null): StockMovement
    {
        $apply = function () use ($data, $prelockedLevel): StockMovement {
            $rawQty = $data['quantity'];
            if (! is_numeric($rawQty) || ! is_finite((float) $rawQty) || abs((float) $rawQty) > 99999999.9999) {
                throw new BusinessException('كمية الحركة غير صالحة.');
            }
            // Work in ten-thousandths to avoid floating point residues at zero.
            $units = (int) round((float) $rawQty * 10000);
            $qty = $units / 10000;

            if ($units === 0) {
                throw new BusinessException('كمية الحركة يجب ألا تساوي صفرًا.');
            }

            $type = $data['type'] instanceof StockMovementType ? $data['type'] : StockMovementType::from($data['type']);
            $bucket = $data['affected_bucket'] instanceof StockBucket ? $data['affected_bucket'] : StockBucket::from($data['affected_bucket']);
            $this->assertValidMovementSemantics($type, $bucket, $units);
            $locationId = $data['location_id'] ?? null;

            // Skip re-locking and location checks if caller already locked this level row.
            if ($prelockedLevel !== null) {
                if ((int) $prelockedLevel->product_variant_id !== (int) $data['product_variant_id']
                    || (int) $prelockedLevel->branch_id !== (int) $data['branch_id']
                    || ($prelockedLevel->location_id === null ? null : (int) $prelockedLevel->location_id) !== ($locationId === null ? null : (int) $locationId)) {
                    throw new \LogicException('الصف المقفول مسبقًا لا يطابق مفتاح الحركة.');
                }
                $stock = $prelockedLevel;
            } else {
                if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('branch_id', $data['branch_id'])->exists()) {
                    throw new BusinessException('الموقع لا يتبع الفرع المحدد.');
                }

                $stock = $this->lockLevels([[
                    'product_variant_id' => $data['product_variant_id'],
                    'branch_id' => $data['branch_id'],
                    'location_id' => $locationId,
                ]])->first();
            }

            // Only new inbound inventory requires active variant and location.
            // Stock transfers, returns, and write-offs remain allowed on inactive items to liquidate stock.
            $isNewInbound = in_array($type, [
                StockMovementType::AdjustmentIn,
                StockMovementType::ProductionReceipt,
                StockMovementType::PurchaseReceipt,
            ], true);
            if ($isNewInbound && $bucket === StockBucket::OnHand && $units > 0) {
                $variant = $stock->variant;
                if ($variant === null || $variant->trashed() || ! $variant->is_active
                    || ($stock->location_id !== null && ! $stock->location?->is_active)) {
                    throw new BusinessException('الإدخال يتطلب متغيرًا وموقعًا نشطين.');
                }
            }

            $bucketKey = $bucket->value;
            $before = (float) $stock->{$bucketKey};
            $afterUnits = (int) round($before * 10000) + $units;
            $after = $afterUnits / 10000;
            if ($afterUnits > 999999999999) {
                throw new BusinessException('الرصيد يتجاوز الحد المسموح.');
            }

            if ($after < 0) {
                throw new BusinessException("الرصيد غير كافٍ في ({$bucketKey}) لإتمام العملية.");
            }

            // Ensure available-for-sale quantity (on_hand - reserved - damaged) does not drop below zero.
            $onHand = $bucket === StockBucket::OnHand ? $after : (float) $stock->on_hand;
            $reserved = $bucket === StockBucket::Reserved ? $after : (float) $stock->reserved;
            $damaged = $bucket === StockBucket::Damaged ? $after : (float) $stock->damaged;
            $availableAfter = (int) round($onHand * 10000) - (int) round($reserved * 10000) - (int) round($damaged * 10000);

            if ($availableAfter < 0) {
                $availableNow = (float) $stock->on_hand - (float) $stock->reserved - (float) $stock->damaged;

                throw new BusinessException("الكمية المتاحة للبيع ({$availableNow}) غير كافية لإتمام العملية.");
            }

            // Created-by is mandatory for audit trails; fails explicitly in queue/CLI contexts without auth user.
            $createdBy = $data['created_by'] ?? auth()->id();
            if ($createdBy === null) {
                throw new \LogicException('recordMovement يتطلب created_by صريحًا عند غياب مستخدم مصادق.');
            }

            // Store morph alias instead of FQCN so future class renames preserve audit trail queries.
            $referenceType = $data['reference_type'] ?? null;
            if (is_string($referenceType) && class_exists($referenceType)) {
                $referenceType = (new $referenceType)->getMorphClass();
            }

            // 4. تسجيل الحركة في دفتر الأستاذ (Ledger)
            $movement = StockMovement::query()->create([
                'reference_number' => $this->settings->generateNextNumber('movement'),
                'product_variant_id' => $data['product_variant_id'],
                'branch_id' => $data['branch_id'],
                'location_id' => $locationId,
                'type' => $type,
                'affected_bucket' => $bucket,
                'quantity' => $qty,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $data['unit_cost'] ?? null,
                'reference_type' => $referenceType,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $createdBy,
                'notes' => $data['notes'] ?? null,
            ]);

            $stock->{$bucketKey} = $after;
            $stock->save();

            // Evaluate low stock threshold: location-specific reorder level triggers on row availability,
            // while variant reorder level triggers on branch-wide aggregate availability.
            $rowLevel = (float) $stock->reorder_level;
            if ($rowLevel > 0) {
                $effectOnAvailable = match ($bucket) {
                    StockBucket::OnHand => $units,
                    StockBucket::Reserved, StockBucket::Damaged => -$units,
                    default => 0,
                };
                $availableBeforeUnits = $availableAfter - $effectOnAvailable;
                $availableAfterQty = $availableAfter / 10000;
                if ($availableBeforeUnits / 10000 > $rowLevel && $availableAfterQty <= $rowLevel) {
                    StockLowDetected::dispatch(
                        (int) $stock->product_variant_id,
                        (int) $stock->branch_id,
                        $availableAfterQty,
                        $rowLevel,
                    );
                }
            }
            $variantLevel = (float) ($stock->variant?->reorder_level ?? 0);
            if ($variantLevel > 0) {
                $effectOnAvailable = match ($bucket) {
                    StockBucket::OnHand => $units,
                    StockBucket::Reserved, StockBucket::Damaged => -$units,
                    default => 0,
                };
                // Check aggregate branch availability within the transaction to see the committed change.
                $branchTotal = (float) (StockLevel::query()
                    ->where('product_variant_id', $stock->product_variant_id)
                    ->where('branch_id', $stock->branch_id)
                    ->selectRaw('COALESCE(SUM(on_hand - reserved - damaged), 0) AS total')
                    ->value('total') ?? 0);
                $branchBefore = $branchTotal - ($effectOnAvailable / 10000);
                if ($branchBefore > $variantLevel && $branchTotal <= $variantLevel) {
                    StockLowDetected::dispatch(
                        (int) $stock->product_variant_id,
                        (int) $stock->branch_id,
                        $branchTotal,
                        $variantLevel,
                    );
                }
            }

            return $movement;
        };

        if ($prelockedLevel !== null) {
            if (DB::transactionLevel() === 0) {
                throw new \LogicException('Prelocked stock requires an active transaction.');
            }

            return $apply();
        }

        return DB::transaction($apply);
    }

    /**
     * Executes multiple stock adjustments atomically with optional idempotency protection.
     *
     * @param  array{branch_id: int, location_id?: int|null, items: list<array{product_variant_id: int, adjustment_type: string, quantity: float|int|string, reason?: string|null}>}  $payload
     * @return list<StockMovement>
     */
    public function applyAdjustments(array $payload, int $userId, ?string $idempotencyKey = null): array
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $payload['branch_id']);
        if ($idempotencyKey === null) {
            return $this->executeAdjustments($payload, $userId);
        }

        $requestHash = IdempotencyFingerprint::hash($payload);

        try {
            return DB::transaction(function () use ($payload, $userId, $idempotencyKey, $requestHash): array {
                $record = InventoryIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $movements = $this->executeAdjustments($payload, $userId);

                $record->update([
                    'response' => array_map(fn (StockMovement $movement) => $movement->id, $movements),
                    'status_code' => 201,
                ]);

                return $movements;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Isolate idempotency key collisions from unrelated unique constraint violations.
            $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (! str_contains($driverMessage, 'inventory_idempotency_keys_user_id_key_unique')
                && ! str_contains($e->getMessage(), 'inventory_idempotency_keys_user_id_key_unique')) {
                throw $e;
            }

            $record = InventoryIdempotencyKey::query()
                ->where('user_id', $userId)->where('key', $idempotencyKey)->first();

            if ($record === null) {
                throw new BusinessException('تعارض في مفتاح عدم التكرار؛ أعد المحاولة.', [], 409);
            }

            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new BusinessException('مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.', [], 409);
            }

            $ids = $record->response ?? [];
            $byId = StockMovement::query()->whereIn('id', $ids)->get()->keyBy('id');

            return array_values(array_filter(array_map(fn ($id) => $byId->get($id), $ids)));
        }
    }

    /**
     * @param  array{branch_id: int, location_id?: int|null, items: list<array{product_variant_id: int, adjustment_type: string, quantity: float|int|string, reason?: string|null}>}  $payload
     * @return list<StockMovement>
     */
    private function executeAdjustments(array $payload, int $userId): array
    {
        return DB::transaction(function () use ($payload, $userId): array {
            app(BranchAccess::class)->assertBranch($userId, (int) $payload['branch_id']);

            // Deactivation allows stock write-offs/decreases, but increases require active variants and locations.
            if (collect($payload['items'])->contains('adjustment_type', 'increase')) {
                $variants = ProductVariant::query()->withTrashed()
                    ->whereIn('id', array_column($payload['items'], 'product_variant_id'))
                    ->get()->keyBy('id');
                $location = ($payload['location_id'] ?? null) !== null ? Location::query()->find($payload['location_id']) : null;

                foreach ($payload['items'] as $item) {
                    if ($item['adjustment_type'] !== 'increase') {
                        continue;
                    }
                    // Soft-deleted variants cannot receive new stock, but write-offs remain allowed.
                    $variant = $variants->get($item['product_variant_id']);
                    if ($variant === null || $variant->trashed() || ! $variant->is_active) {
                        throw new BusinessException('لا يمكن الإدخال على متغير معطّل؛ فعّله أولًا أو استخدم عمليات الإخراج لتصفية رصيده.');
                    }
                    if ($location !== null && ! $location->is_active) {
                        throw new BusinessException('لا يمكن الإدخال إلى موقع معطّل؛ فعّله أولًا.');
                    }
                }
            }
            $levels = $this->lockLevels(array_map(fn ($item) => [
                'product_variant_id' => $item['product_variant_id'],
                'branch_id' => $payload['branch_id'],
                'location_id' => $payload['location_id'] ?? null,
            ], $payload['items']));
            $movements = [];

            foreach ($payload['items'] as $item) {
                // Apply adjustment directly to prelocked level instance.
                $level = $levels->get($this->levelKey([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id' => $payload['branch_id'],
                    'location_id' => $payload['location_id'] ?? null,
                ]));
                if (in_array($item['adjustment_type'], ['write_off_damaged', 'restore_damaged'], true)) {
                    $base = [
                        'product_variant_id' => $item['product_variant_id'],
                        'branch_id' => $payload['branch_id'], 'location_id' => $payload['location_id'] ?? null,
                        'created_by' => $userId, 'notes' => $item['reason'] ?? null,
                        'quantity' => -abs((float) $item['quantity']),
                    ];
                    $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageRelease, 'affected_bucket' => StockBucket::Damaged], $level);
                    if ($item['adjustment_type'] === 'write_off_damaged') {
                        $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageWriteOff, 'affected_bucket' => StockBucket::OnHand], $level);
                    }

                    continue;
                }
                [$type, $bucket, $sign] = match ($item['adjustment_type']) {
                    'increase' => [StockMovementType::AdjustmentIn, StockBucket::OnHand, 1],
                    'decrease' => [StockMovementType::AdjustmentOut, StockBucket::OnHand, -1],
                    'mark_damaged' => [StockMovementType::Damage, StockBucket::Damaged, 1],
                    default => throw new BusinessException('نوع تسوية غير معروف: '.$item['adjustment_type']),
                };

                $movements[] = $this->recordMovement([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id' => $payload['branch_id'],
                    'location_id' => $payload['location_id'] ?? null,
                    'type' => $type,
                    'affected_bucket' => $bucket,
                    'quantity' => $sign * abs((float) $item['quantity']),
                    'created_by' => $userId,
                    'notes' => $item['reason'] ?? null,
                ], $level);
            }

            return $movements;
        });
    }

    /**
     * Current stock level for a variant at a branch and location (read-only snapshot).
     */
    public function levelFor(int $variantId, int $branchId, ?int $locationId): ?StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->where('location_id', $locationId)
            ->first();
    }

    /**
     * Acquire every stock key before a multi-item operation, in one global order.
     *
     * @param  list<array{product_variant_id: int, branch_id: int, location_id?: int|null}>  $keys
     * @return Collection<string, StockLevel>
     */
    public function lockLevels(array $keys): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock locks require an active transaction.');
        }
        // Enforce 5s lock timeout to fail fast with 409 Conflict instead of holding PHP worker threads.
        $keys = collect($keys)->unique(fn ($key) => $this->levelKey($key))
            ->sortBy(fn ($key) => [(int) $key['branch_id'], (int) ($key['location_id'] ?? 0), (int) $key['product_variant_id']]);
        if ($keys->isEmpty()) {
            return collect();
        }
        $now = now();
        $previousTimeout = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
        DB::statement("SET LOCAL lock_timeout = '5s'");

        try {
            // Lock variants before stock rows in every path, including callers
            // that prelock a batch of balances before recording movements.
            // H1: shared (FOR SHARE) lock — movements only need to block
            // concurrent variant edits (which take FOR UPDATE), while shared
            // locks don't block each other, so branches run in parallel.
            // The real serialization stays on stock_levels (FOR UPDATE below).
            $variantIds = $keys->pluck('product_variant_id')->unique()->sort()->values();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->sharedLock()->get();
            if ($variants->count() !== $variantIds->count()) {
                throw new BusinessException('أحد المتغيرات لم يعد متاحًا.');
            }

            // Aggregate threshold checks must see earlier movements at other
            // locations in this branch. Lock only pairs with a branch threshold.
            $thresholdIds = $variants->filter(fn ($variant) => (float) $variant->reorder_level > 0)->modelKeys();
            $pairs = $keys->filter(fn ($key) => in_array((int) $key['product_variant_id'], $thresholdIds, true))
                ->unique(fn ($key) => $key['branch_id'].':'.$key['product_variant_id'])
                ->sortBy(fn ($key) => [(int) $key['branch_id'], (int) $key['product_variant_id']]);
            foreach ($pairs as $key) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    'stock-threshold:'.$key['branch_id'].':'.$key['product_variant_id'],
                ]);
            }

            // M2: batch location→branch ownership check inside the lock path,
            // so executeAdjustments cannot plant (branch A, location of branch
            // B) rows through the prelocked path. The composite FK migration
            // enforces the same invariant at the database level.
            $locationIds = $keys->pluck('location_id')->filter(fn ($id) => $id !== null)->unique()->values();
            if ($locationIds->isNotEmpty()) {
                $branchByLocation = Location::query()->whereIn('id', $locationIds->all())->pluck('branch_id', 'id');
                foreach ($keys as $key) {
                    if (($key['location_id'] ?? null) === null) {
                        continue;
                    }
                    $actual = $branchByLocation->get($key['location_id']);
                    if ($actual === null || (int) $actual !== (int) $key['branch_id']) {
                        throw new BusinessException('الموقع لا يتبع الفرع المحدد.');
                    }
                }
            }

            // Seed missing stock level rows with insertOrIgnore before acquiring exclusive row locks.
            $rows = $keys->map(fn ($key) => [
                'product_variant_id' => $key['product_variant_id'], 'branch_id' => $key['branch_id'],
                'location_id' => $key['location_id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ])->values();
            foreach ($rows->chunk(1000) as $chunk) {
                DB::table('stock_levels')->insertOrIgnore($chunk->all());
            }

            $levels = StockLevel::query()->with(['variant:id,product_id,is_active,deleted_at,reorder_level', 'location:id,is_active'])->where(function ($query) use ($keys): void {
                foreach ($keys->groupBy(fn ($key) => $key['branch_id'].':'.($key['location_id'] ?? 0)) as $group) {
                    $identity = $group->first();
                    $query->orWhere(fn ($scope) => $scope->where('branch_id', $identity['branch_id'])
                        ->where('location_id', $identity['location_id'] ?? null)
                        ->whereIn('product_variant_id', $group->pluck('product_variant_id')->all()));
                }
            })->orderBy('branch_id')->orderByRaw('location_id ASC NULLS FIRST')->orderBy('product_variant_id')
                ->lockForUpdate()->get()->keyBy(fn ($stock) => $this->levelKey($stock->getAttributes()));

            // M-11: بعد قفل صفوف الأرصدة يُفحص is_active للموقع والفرع تحت
            // قفل FOR SHARE — يقفل التعطيل (FOR UPDATE على نفس الصفوف)
            // فيتسلسل المساران: إما يرى المخزونُ التعطيلَ فيرفض، أو يرى
            // التعطيلُ الرصيدَ الجديد فيرفض. بلا هذا يدخل رصيد بين الفحص والتعطيل.
            $branchIds = $keys->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values();
            if ($branchIds->isNotEmpty()) {
                $inactiveBranches = Branch::query()->whereIn('id', $branchIds->all())->sharedLock()->pluck('is_active', 'id');
                foreach ($branchIds as $branchId) {
                    if ($inactiveBranches->has($branchId) && ! (bool) $inactiveBranches->get($branchId)) {
                        throw new BusinessException('الفرع متوقف حالياً ولا يقبل حركات مخزنية.');
                    }
                }
            }
            if ($locationIds->isNotEmpty()) {
                $inactiveLocations = Location::query()->whereIn('id', $locationIds->all())->sharedLock()->pluck('is_active', 'id');
                foreach ($locationIds as $locationId) {
                    if ($inactiveLocations->has($locationId) && ! (bool) $inactiveLocations->get($locationId)) {
                        throw new BusinessException('الموقع متوقف حالياً ولا يقبل حركات مخزنية.');
                    }
                }
            }
        } catch (QueryException $e) {
            // Keep deadlocks as database exceptions so the outer transaction
            // can retry them. The API exception handler returns 409 if exhausted.
            if (($e->errorInfo[0] ?? $e->getCode()) === '55P03') {
                throw new BusinessException('المخزون مشغول بعملية أخرى الآن؛ أعد المحاولة بعد لحظات.', [], 409);
            }

            throw $e;
        } catch (\Throwable $e) {
            DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);
            throw $e;
        }

        // Restore the caller's transaction-local setting, not the session default.
        // Failed PostgreSQL transactions must roll back before further SQL.
        DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);

        return $levels;
    }

    /**
     * Generates a composite key uniquely identifying a branch-location-variant level row.
     *
     * @param  array{branch_id: int|string, location_id?: int|string|null, product_variant_id: int|string}  $key
     */
    public function levelKey(array $key): string
    {
        return $key['branch_id'].':'.($key['location_id'] ?? 0).':'.$key['product_variant_id'];
    }

    /**
     * Resolves the primary receiving location for a branch, defaulting to designated receiving type.
     */
    public function receivingLocation(int $branchId, ?int $locationId = null): ?int
    {
        $query = Location::query()->where('branch_id', $branchId)->where('is_active', true);
        if ($locationId !== null) {
            if (! $query->whereKey($locationId)->exists()) {
                throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع.');
            }

            return $locationId;
        }
        $id = $query->orderByRaw("CASE WHEN type = 'receiving' THEN 0 ELSE 1 END")->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Validates movement type compatibility with affected stock bucket and sign of quantity.
     */
    private function assertValidMovementSemantics(StockMovementType $type, StockBucket $bucket, int $units): void
    {
        // Default branch prevents unhandled match errors if new enum cases are introduced.
        [$allowed, $positive] = match ($type) {
            StockMovementType::ProductionReceipt, StockMovementType::PurchaseReceipt, StockMovementType::SaleReturn,
            StockMovementType::AdjustmentIn, StockMovementType::StocktakeGain, StockMovementType::TransferIn => [[StockBucket::OnHand], true],
            StockMovementType::Sale, StockMovementType::AdjustmentOut,
            StockMovementType::StocktakeLoss, StockMovementType::TransferOut,
            StockMovementType::DamageWriteOff => [[StockBucket::OnHand], false],
            StockMovementType::Damage => [[StockBucket::Damaged], true],
            StockMovementType::DamageRelease => [[StockBucket::Damaged], false],
            StockMovementType::Reserve => [[StockBucket::Reserved], true],
            StockMovementType::ReleaseReserve => [[StockBucket::Reserved], false],
            StockMovementType::TransferInTransit => [[StockBucket::InTransit], true],
            StockMovementType::TransferTransitRelease, StockMovementType::TransferShortage => [[StockBucket::InTransit], false],
            default => throw new BusinessException('نوع حركة مخزنية غير مدعوم: '.$type->value),
        };
        if (! in_array($bucket, $allowed, true) || ($units > 0) !== $positive) {
            throw new BusinessException('نوع حركة المخزون لا يطابق وعاء المخزون أو اتجاه الكمية.');
        }
    }
}
