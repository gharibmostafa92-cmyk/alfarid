<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\StockLowDetected;
use App\Exceptions\BusinessException;
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
 * القلب النابض للمخزون — الطريق الوحيد لتغيير أي رصيد في النظام.
 *
 * قاعدة صارمة: ممنوع تعديل stock_levels من أي Controller أو Model آخر.
 * كل تغيير يمر من هنا: تسجيل حركة في دفتر الأستاذ (stock_movements) أولًا،
 * ثم تحديث الرصيد — داخل نفس الـ transaction ومع قفل الصف.
 */
class InventoryService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * الدالة المركزية الوحيدة لتغيير أي رصيد في النظام.
     *
     * @param  array{product_variant_id: int, branch_id: int, location_id?: int|null, type: StockMovementType|string, affected_bucket: StockBucket|string, quantity: float|int|string, unit_cost?: float|null, reference_type?: string|null, reference_id?: int|null, created_by?: int|null, notes?: string|null}  $data
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

            // صف مقفول مسبقًا من المستدعي (lockLevels): لا إعادة قفل ولا فحص موقع —
            // المستدعي ضمن الصف والموقع عند القفل. خلاف ذلك نقفل هنا ونتحقق.
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

            // H3: فحص النشاط للإدخال الجديد فقط (بضاعة تدخل المخزون أول مرة)،
            // لا للنقل/المرتجع/الجرد — البضاعة موجودة فعلًا فيجب تسجيلها أينما
            // كانت، وتصفية رصيد المعطّل متاحة بعمليات الإخراج (M5).
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

            // 2. التحقق: الرصيد المستهدف لا ينزل تحت الصفر
            if ($after < 0) {
                throw new BusinessException("الرصيد غير كافٍ في ({$bucketKey}) لإتمام العملية.");
            }

            // 3. التحقق: الكمية المتاحة للبيع لا تصبح سالبة بعد الحركة
            //    (يغطي حجز/تالف جديد أكبر من المتاح، وسحب فعلي يبتلع محجوزًا أو تالفًا)
            $onHand = $bucket === StockBucket::OnHand ? $after : (float) $stock->on_hand;
            $reserved = $bucket === StockBucket::Reserved ? $after : (float) $stock->reserved;
            $damaged = $bucket === StockBucket::Damaged ? $after : (float) $stock->damaged;
            $availableAfter = (int) round($onHand * 10000) - (int) round($reserved * 10000) - (int) round($damaged * 10000);

            if ($availableAfter < 0) {
                $availableNow = (float) $stock->on_hand - (float) $stock->reserved - (float) $stock->damaged;

                throw new BusinessException("الكمية المتاحة للبيع ({$availableNow}) غير كافية لإتمام العملية.");
            }

            // منفّذ الحركة إلزامي: من console/queue بلا مستخدم مصادق وبلا created_by
            // صريح، الفشل الواضح أفضل من QueryException على قيد NOT NULL.
            $createdBy = $data['created_by'] ?? auth()->id();
            if ($createdBy === null) {
                throw new \LogicException('recordMovement يتطلب created_by صريحًا عند غياب مستخدم مصادق.');
            }

            // مرجع الدفتر يُخزن بالاسم القصير من morph map إن وُجد (stock_transfer/stocktake)
            // حتى لا تكسر إعادة تسمية الكلاسات أثر التدقيق؛ الأسماء الكاملة القديمة تظل مقروءة.
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

            // 5. تحديث جدول الأرصدة من واقع الحركة
            $stock->{$bucketKey} = $after;
            $stock->save();

            // 6. حدث انخفاض المخزون: فقط عند عبور حد إعادة الطلب الفعّال نزولًا
            //    (الحد الفعّال = حد صف الرصيد إن وُجد وإلا حد المتغير) حتى لا
            //    تتكرر التنبيهات مع كل حركة والرصيد تحت الحد أصلًا.
            // حد المتغير متاح بلا استعلام إضافي: lockLevels يحمّل العلاقة
            // جزئيًا بالأعمدة id/is_active/deleted_at/reorder_level.
            $level = (float) $stock->reorder_level;
            if ($level <= 0) {
                $level = (float) ($stock->variant?->reorder_level ?? 0);
            }
            if ($level > 0) {
                $effectOnAvailable = match ($bucket) {
                    StockBucket::OnHand => $units,
                    StockBucket::Reserved, StockBucket::Damaged => -$units,
                    default => 0,
                };
                $availableBeforeUnits = $availableAfter - $effectOnAvailable;
                $availableAfterQty = $availableAfter / 10000;
                if ($availableBeforeUnits / 10000 > $level && $availableAfterQty <= $level) {
                    StockLowDetected::dispatch(
                        (int) $stock->product_variant_id,
                        (int) $stock->branch_id,
                        $availableAfterQty,
                        $level,
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
     * تسوية يدوية متعددة العناصر داخل transaction واحدة (أي فشل يلغي الكل).
     *
     * مع مفتاح عدم تكرار (Idempotency-Key): يُدرج المفتاح داخل نفس الـ transaction،
     * فالتكرار المتزامن يصطدم بالقيد الفريد بعد commit الأول ويعيد الحركات المحفوظة،
     * ونفس المفتاح بحمولة مختلفة يُرفض 409، وفشل الطلب يلغي المفتاح معه.
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
            // الالتقاط خاص بقيد مفتاح عدم التكرار وحده: أي انتهاك فريد آخر داخل
            // المعاملة خطأ حقيقي يجب أن يظهر، لا أن يُقنَّع كتعارض مفتاح (L-2).
            // L4: اسم القيد من رسالة السائق (errorInfo) لا من نص الاستثناء العام.
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

            // M5: التعطيل لا يحبس الرصيد — عمليات الإخراج (decrease/تالف/شطب) مسموحة على
            // متغير/موقع معطّل لتصفية رصيده، والإدخال (increase) وحده يتطلب النشاط.
            if (collect($payload['items'])->contains('adjustment_type', 'increase')) {
                $variants = ProductVariant::query()->withTrashed()
                    ->whereIn('id', array_column($payload['items'], 'product_variant_id'))
                    ->get()->keyBy('id');
                $location = ($payload['location_id'] ?? null) !== null ? Location::query()->find($payload['location_id']) : null;

                foreach ($payload['items'] as $item) {
                    if ($item['adjustment_type'] !== 'increase') {
                        continue;
                    }
                    // المحذوف ناعمًا يعامل كمعطّل للإدخال، لكن إخراجه يظل مسموحًا
                    // حتى لا يُحبس رصيده للأبد (M-9).
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
                // الصف مقفول مسبقًا أعلاه — تُمرَّر حركتا العنصر عليه مباشرة.
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

    /** الرصيد الحالي لمتغير في فرع/موقع (للقطات الجرد ونحوها) — قراءة فقط. */
    public function levelFor(int $variantId, int $branchId, ?int $locationId): ?StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->where('location_id', $locationId)
            ->first();
    }

    /** Acquire every stock key before a multi-item operation, in one global order. */
    public function lockLevels(array $keys): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock locks require an active transaction.');
        }
        // انتظار محدود للأقفال داخل هذه المعاملة فقط؛ التنافس الطويل يتحول لرفض
        // واضح (409) بدل تعليق الطلب حتى مهلة PHP-FPM.
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

            // الإدخال نفسه قد ينتظر قفلًا: ON CONFLICT ينتظر معاملة أخرى أدخلت
            // نفس المفتاح ولم تعتمد بعد، فيخضع لنفس lock_timeout — لازم يكون
            // جوه الـ try حتى يتحول الانتظار الطويل لـ 409 لا 500 (M-2).
            $rows = $keys->map(fn ($key) => [
                'product_variant_id' => $key['product_variant_id'], 'branch_id' => $key['branch_id'],
                'location_id' => $key['location_id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ])->values();
            foreach ($rows->chunk(1000) as $chunk) {
                DB::table('stock_levels')->insertOrIgnore($chunk->all());
            }

            $levels = StockLevel::query()->with(['variant:id,is_active,deleted_at,reorder_level', 'location:id,is_active'])->where(function ($query) use ($keys): void {
                foreach ($keys->groupBy(fn ($key) => $key['branch_id'].':'.($key['location_id'] ?? 0)) as $group) {
                    $identity = $group->first();
                    $query->orWhere(fn ($scope) => $scope->where('branch_id', $identity['branch_id'])
                        ->where('location_id', $identity['location_id'] ?? null)
                        ->whereIn('product_variant_id', $group->pluck('product_variant_id')->all()));
                }
            })->orderBy('branch_id')->orderByRaw('location_id ASC NULLS FIRST')->orderBy('product_variant_id')
                ->lockForUpdate()->get()->keyBy(fn ($stock) => $this->levelKey($stock->getAttributes()));
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

    public function levelKey(array $key): string
    {
        return $key['branch_id'].':'.($key['location_id'] ?? 0).':'.$key['product_variant_id'];
    }

    public function receivingLocation(int $branchId, ?int $locationId = null): ?int
    {
        $query = Location::query()->where('branch_id', $branchId)->where('is_active', true);
        if ($locationId !== null) {
            if (! $query->whereKey($locationId)->exists()) throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع.');
            return $locationId;
        }
        $id = $query->orderByRaw("CASE WHEN type = 'receiving' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
        return $id === null ? null : (int) $id;
    }

    private function assertValidMovementSemantics(StockMovementType $type, StockBucket $bucket, int $units): void
    {
        // L4: فرع default حتى لا ينفجر نوع جديد بـ UnhandledMatchError (500).
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
            throw new BusinessException('نوع الحركة لا يطابق وعاء المخزون أو اتجاه الكمية.');
        }
    }
}
