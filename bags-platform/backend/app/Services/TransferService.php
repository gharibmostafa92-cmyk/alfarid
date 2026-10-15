<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessException;
use App\Models\StockTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * دورة حياة التحويلات بين الفروع. كل أثر مخزني بيمر من InventoryService فقط.
 *
 * draft: لا أثر مخزني. approve: حجز في المرسل. ship: فك الحجز + إخراج فعلي +
 * in_transit عند المستلم. receive: تسوية in_transit + إضافة الفعلي المستلم.
 */
class TransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'creator'])
            ->when($filters['from_branch_id'] ?? null, fn ($q, $v) => $q->where('from_branch_id', $v))
            ->when($filters['to_branch_id'] ?? null, fn ($q, $v) => $q->where('to_branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): StockTransfer
    {
        return StockTransfer::query()
            ->with(['items.variant', 'items.fromLocation', 'items.toLocation', 'fromBranch', 'toBranch', 'creator', 'approver'])
            ->findOrFail($id);
    }

    public function create(array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'transfer_number' => $this->settings->generateNextNumber('transfer'),
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => TransferStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($transfer, $data['items']);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
        });
    }

    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertStatus($transfer, TransferStatus::Draft, 'التعديل');

            $transfer->update([
                'from_branch_id' => $data['from_branch_id'] ?? $transfer->from_branch_id,
                'to_branch_id' => $data['to_branch_id'] ?? $transfer->to_branch_id,
                'notes' => $data['notes'] ?? $transfer->notes,
            ]);

            if (isset($data['items'])) {
                $transfer->items()->delete();
                $this->syncItems($transfer, $data['items']);
            }

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
        });
    }

    /** الاعتماد: حجز الكميات المعتمدة في الفرع المرسل حتى لا تُباع قبل الشحن. */
    public function approve(StockTransfer $transfer, array $itemsPayload, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items');
            $this->assertStatus($transfer, TransferStatus::Draft, 'الاعتماد');

            foreach ($transfer->items as $item) {
                $approvedQty = (float) ($itemsPayload[$item->id] ?? $item->requested_qty);

                if ($approvedQty < 0 || $approvedQty > (float) $item->requested_qty) {
                    throw new BusinessException('الكمية المعتمدة يجب أن تكون بين صفر والكمية المطلوبة.');
                }

                $item->update(['approved_qty' => $approvedQty]);

                if ($approvedQty > 0) {
                    $this->inventory->recordMovement([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::Reserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => $approvedQty,
                        'unit_cost' => $item->variant?->cost_price,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'created_by' => $userId,
                        'notes' => 'حجز لصالح تحويل '.$transfer->transfer_number,
                    ]);
                }
            }

            $transfer->update(['status' => TransferStatus::Approved, 'approved_by' => $userId]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /** الشحن: فك الحجز وإخراج البضاعة من المرسل، وتسجيلها "في الطريق" عند المستلم. */
    public function ship(StockTransfer $transfer, array $itemsPayload, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items');
            $this->assertStatus($transfer, TransferStatus::Approved, 'الشحن');

            foreach ($transfer->items as $item) {
                $shippedQty = (float) ($itemsPayload[$item->id] ?? $item->approved_qty);

                if ($shippedQty < 0 || $shippedQty > (float) $item->approved_qty) {
                    throw new BusinessException('الكمية المشحونة يجب أن تكون بين صفر والكمية المعتمدة.');
                }

                $item->update(['shipped_qty' => $shippedQty]);

                if ($shippedQty <= 0) {
                    continue;
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'created_by' => $userId,
                ];

                // المرسل: فك الحجز ثم إخراج فعلي
                $this->inventory->recordMovement([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::ReleaseReserve,
                    'affected_bucket' => StockBucket::Reserved,
                    'quantity' => -$shippedQty,
                    'notes' => 'فك حجز عند شحن تحويل '.$transfer->transfer_number,
                ]);
                $this->inventory->recordMovement([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::TransferOut,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => -$shippedQty,
                    'notes' => 'شحن تحويل '.$transfer->transfer_number,
                ]);

                // الباقي المحجوز (معتمد ولم يُشحن) يرجع متاحًا للبيع
                $unshipped = (float) $item->approved_qty - $shippedQty;
                if ($unshipped > 0) {
                    $this->inventory->recordMovement([...$base,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => -$unshipped,
                        'notes' => 'فك حجز الكمية المعتمدة غير المشحونة من تحويل '.$transfer->transfer_number,
                    ]);
                }

                // المستلم: بضاعة في الطريق
                $this->inventory->recordMovement([...$base,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'type' => StockMovementType::TransferIn,
                    'affected_bucket' => StockBucket::InTransit,
                    'quantity' => $shippedQty,
                    'notes' => 'بضاعة في الطريق من تحويل '.$transfer->transfer_number,
                ]);
            }

            $transfer->update(['status' => TransferStatus::Shipped, 'shipped_at' => now()]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /** الاستلام: تسوية in_transit بالكامل، وإضافة المستلم فعليًا، وتسوية أي عجز كحركة مستقلة. */
    public function receive(StockTransfer $transfer, array $itemsPayload, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items');
            $this->assertStatus($transfer, TransferStatus::Shipped, 'الاستلام');

            $shortageNotes = [];

            foreach ($transfer->items as $item) {
                $receivedQty = (float) ($itemsPayload[$item->id] ?? $item->shipped_qty);
                $shippedQty = (float) $item->shipped_qty;

                if ($receivedQty < 0 || $receivedQty > $shippedQty) {
                    throw new BusinessException('الكمية المستلمة يجب أن تكون بين صفر والكمية المشحونة.');
                }

                $item->update(['received_qty' => $receivedQty]);

                if ($shippedQty <= 0) {
                    continue;
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'created_by' => $userId,
                ];

                // خروج الجزء المستلم من "في الطريق" ودخوله الرصيد الفعلي
                if ($receivedQty > 0) {
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::TransferIn,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ]);
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::TransferIn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ]);
                }

                // العجز بين المشحون والمستلم: تسوية مستقلة موثقة من رصيد "في الطريق"
                $shortage = $shippedQty - $receivedQty;
                if ($shortage > 0) {
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::AdjustmentOut,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$shortage,
                        'notes' => 'عجز عند استلام تحويل '.$transfer->transfer_number,
                    ]);
                    $shortageNotes[] = "عجز {$shortage} وحدة في المتغير #{$item->product_variant_id}";
                }
            }

            $notes = $transfer->notes;
            if ($shortageNotes !== []) {
                $notes = trim(($notes ? $notes.' | ' : '').implode('؛ ', $shortageNotes));
            }

            $transfer->update(['status' => TransferStatus::Received, 'received_at' => now(), 'notes' => $notes]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /** الإلغاء: من المسودة مباشرة، ومن المعتمد بعد فك الحجز. المشحون لا يُلغى (يُستلم). */
    public function cancel(StockTransfer $transfer, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items');

            if ($transfer->status === TransferStatus::Approved) {
                foreach ($transfer->items as $item) {
                    if ((float) $item->approved_qty <= 0) {
                        continue;
                    }

                    $this->inventory->recordMovement([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => -(float) $item->approved_qty,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'created_by' => $userId,
                        'notes' => 'فك حجز عند إلغاء تحويل '.$transfer->transfer_number,
                    ]);
                }
            } elseif ($transfer->status !== TransferStatus::Draft) {
                throw new BusinessException('لا يمكن إلغاء تحويل بحالة '.$transfer->status->value.'.');
            }

            $transfer->update(['status' => TransferStatus::Cancelled]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف تحويل إلا في حالة المسودة أو الملغي.');
            }

            $transfer->delete();
        });
    }

    private function assertStatus(StockTransfer $transfer, TransferStatus $expected, string $action): void
    {
        if ($transfer->status !== $expected) {
            throw new BusinessException("لا يمكن تنفيذ {$action} على تحويل بحالة {$transfer->status->value}.");
        }
    }

    /**
     * @param  list<array{product_variant_id: int, from_location_id?: int|null, to_location_id?: int|null, requested_qty: float|int|string}>  $items
     */
    private function syncItems(StockTransfer $transfer, array $items): void
    {
        foreach ($items as $item) {
            $transfer->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'from_location_id' => $item['from_location_id'] ?? null,
                'to_location_id' => $item['to_location_id'] ?? null,
                'requested_qty' => $item['requested_qty'],
            ]);
        }
    }
}
