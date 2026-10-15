<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\StockLevel;
use App\Models\StockMovement;
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
            $qty = (float) $data['quantity'];

            if ($qty === 0.0) {
                throw new BusinessException('كمية الحركة يجب ألا تساوي صفرًا.');
            }

            $type = $data['type'] instanceof StockMovementType ? $data['type'] : StockMovementType::from($data['type']);
            $bucket = $data['affected_bucket'] instanceof StockBucket ? $data['affected_bucket'] : StockBucket::from($data['affected_bucket']);
            $locationId = $data['location_id'] ?? null;

            // 1. جلب سجل الرصيد أو إنشاؤه (createOrFirst آمن ضد سباق الإنشاء)، ثم قفل الصف
            StockLevel::query()->firstOrCreate([
                'product_variant_id' => $data['product_variant_id'],
                'branch_id' => $data['branch_id'],
                'location_id' => $locationId,
            ]);

            $stock = StockLevel::query()
                ->where('product_variant_id', $data['product_variant_id'])
                ->where('branch_id', $data['branch_id'])
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();

            $bucketKey = $bucket->value;
            $before = (float) $stock->{$bucketKey};
            $after = $before + $qty;

            // 2. التحقق: الرصيد المستهدف لا ينزل تحت الصفر
            if ($after < 0) {
                throw new BusinessException("الرصيد غير كافٍ في ({$bucketKey}) لإتمام العملية.");
            }

            // 3. التحقق: الكمية المتاحة للبيع لا تصبح سالبة بعد الحركة
            //    (يغطي حجز/تالف جديد أكبر من المتاح، وسحب فعلي يبتلع محجوزًا أو تالفًا)
            $onHand = $bucket === StockBucket::OnHand ? $after : (float) $stock->on_hand;
            $reserved = $bucket === StockBucket::Reserved ? $after : (float) $stock->reserved;
            $damaged = $bucket === StockBucket::Damaged ? $after : (float) $stock->damaged;
            $availableAfter = $onHand - $reserved - $damaged;

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
     * @param  array{branch_id: int, location_id?: int|null, items: list<array{product_variant_id: int, adjustment_type: string, quantity: float|int|string, reason?: string|null}>}  $payload
     * @return list<StockMovement>
     */
    public function applyAdjustments(array $payload, int $userId): array
    {
        return DB::transaction(function () use ($payload, $userId): array {
            $movements = [];

            foreach ($payload['items'] as $item) {
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
}
