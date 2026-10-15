<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\InventoryIdempotencyKey;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
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
    public function recordMovement(array $data): StockMovement
    {
        return DB::transaction(function () use ($data): StockMovement {
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
            if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('branch_id', $data['branch_id'])->exists()) {
                throw new BusinessException('الموقع لا يتبع الفرع المحدد.');
            }

            $levels = $this->lockLevels([[
                'product_variant_id' => $data['product_variant_id'],
                'branch_id' => $data['branch_id'],
                'location_id' => $locationId,
            ]]);

            $stock = $levels->first();

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
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'notes' => $data['notes'] ?? null,
            ]);

            // 5. تحديث جدول الأرصدة من واقع الحركة
            $stock->{$bucketKey} = $after;
            $stock->save();

            return $movement;
        });
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
        if ($idempotencyKey === null) {
            return $this->executeAdjustments($payload, $userId);
        }

        $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

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
        } catch (UniqueConstraintViolationException) {
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
            $this->lockLevels(array_map(fn ($item) => [
                'product_variant_id' => $item['product_variant_id'],
                'branch_id' => $payload['branch_id'],
                'location_id' => $payload['location_id'] ?? null,
            ], $payload['items']));
            $movements = [];

            foreach ($payload['items'] as $item) {
                if (in_array($item['adjustment_type'], ['write_off_damaged', 'restore_damaged'], true)) {
                    $base = [
                        'product_variant_id' => $item['product_variant_id'],
                        'branch_id' => $payload['branch_id'], 'location_id' => $payload['location_id'] ?? null,
                        'created_by' => $userId, 'notes' => $item['reason'] ?? null,
                        'quantity' => -abs((float) $item['quantity']),
                    ];
                    $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageRelease, 'affected_bucket' => StockBucket::Damaged]);
                    if ($item['adjustment_type'] === 'write_off_damaged') {
                        $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageWriteOff, 'affected_bucket' => StockBucket::OnHand]);
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
                ]);
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
        $keys = collect($keys)->unique(fn ($key) => $this->levelKey($key))
            ->sortBy(fn ($key) => [(int) $key['branch_id'], (int) ($key['location_id'] ?? 0), (int) $key['product_variant_id']]);
        if ($keys->isEmpty()) {
            return collect();
        }
        $now = now();
        DB::table('stock_levels')->insertOrIgnore($keys->map(fn ($key) => [
            'product_variant_id' => $key['product_variant_id'], 'branch_id' => $key['branch_id'],
            'location_id' => $key['location_id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());

        $levels = StockLevel::query()->where(function ($query) use ($keys): void {
            foreach ($keys->groupBy(fn ($key) => $key['branch_id'].':'.($key['location_id'] ?? 0)) as $group) {
                $identity = $group->first();
                $query->orWhere(fn ($scope) => $scope->where('branch_id', $identity['branch_id'])
                    ->where('location_id', $identity['location_id'] ?? null)
                    ->whereIn('product_variant_id', $group->pluck('product_variant_id')->all()));
            }
        })->orderBy('branch_id')->orderByRaw('location_id ASC NULLS FIRST')->orderBy('product_variant_id')
            ->lockForUpdate()->get()->keyBy(fn ($stock) => $this->levelKey($stock->getAttributes()));

        return $levels;
    }

    public function levelKey(array $key): string
    {
        return $key['branch_id'].':'.($key['location_id'] ?? 0).':'.$key['product_variant_id'];
    }

    private function assertValidMovementSemantics(StockMovementType $type, StockBucket $bucket, int $units): void
    {
        [$allowed, $positive] = match ($type) {
            StockMovementType::PurchaseReceipt, StockMovementType::SaleReturn,
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
        };
        if (! in_array($bucket, $allowed, true) || ($units > 0) !== $positive) {
            throw new BusinessException('نوع الحركة لا يطابق وعاء المخزون أو اتجاه الكمية.');
        }
    }

    /** ترتيب حتمي لمفاتيح الحمولة قبل الحوسبة (نفس البيانات = نفس البصمة دائمًا). */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn ($item) => $this->canonicalize($item), $value);

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}
