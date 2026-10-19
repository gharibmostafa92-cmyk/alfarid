<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Events\TransferPendingApproval;
use App\Exceptions\BusinessException;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Concerns\HandlesIdempotentCreation;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دورة حياة التحويلات بين الفروع. كل أثر مخزني بيمر من InventoryService فقط.
 *
 * draft: لا أثر مخزني. approve: حجز في المرسل. ship: فك الحجز + إخراج فعلي +
 * in_transit عند المستلم. receive: تسوية in_transit + إضافة الفعلي المستلم.
 */
class TransferService
{
    use HandlesIdempotentCreation;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->when($filters['staff_branch_id'] ?? null, fn ($q, $branch) => $q->where(fn ($scope) => $scope->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))
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

    public function create(array $data, int $userId, ?string $idempotencyKey = null): StockTransfer
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $data['from_branch_id']);

        /** @var StockTransfer */
        return $this->createIdempotently(
            'transfer_store',
            $data,
            $userId,
            $idempotencyKey,
            StockTransfer::class,
            ['items.variant', 'fromBranch', 'toBranch', 'creator'],
            fn (): StockTransfer => $this->doCreate($data, $userId),
        );
    }

    private function doCreate(array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId): StockTransfer {
            app(BranchAccess::class)->assertBranch($userId, (int) $data['from_branch_id']);
            $transfer = StockTransfer::query()->create([
                'transfer_number' => $this->settings->generateNextNumber('transfer'),
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => TransferStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($transfer, $data['items']);

            $created = $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
            TransferPendingApproval::dispatch($created);

            return $created;
        });
    }

    public function update(StockTransfer $transfer, array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertStatus($transfer, TransferStatus::Draft, 'التعديل');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            app(BranchAccess::class)->assertBranch($userId, (int) ($data['from_branch_id'] ?? $transfer->from_branch_id));

            $fromBranch = $data['from_branch_id'] ?? $transfer->from_branch_id;
            $toBranch = $data['to_branch_id'] ?? $transfer->to_branch_id;
            if ((int) $fromBranch === (int) $toBranch) {
                throw new BusinessException('يجب اختلاف الفرع المرسل عن الفرع المستلم.');
            }
            $items = $data['items'] ?? $transfer->items->toArray();
            foreach ($items as $item) {
                foreach (['from_location_id' => $fromBranch, 'to_location_id' => $toBranch] as $field => $branchId) {
                    if (isset($item[$field]) && ! Location::query()->whereKey($item[$field])->where('branch_id', $branchId)->exists()) {
                        throw new BusinessException('موقع التحويل لا يتبع الفرع المحدد.');
                    }
                }
            }

            $transfer->update([
                'from_branch_id' => $data['from_branch_id'] ?? $transfer->from_branch_id,
                'to_branch_id' => $data['to_branch_id'] ?? $transfer->to_branch_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transfer->notes,
            ]);

            if (isset($data['items'])) {
                $transfer->items()->delete();
                $this->syncItems($transfer, $data['items']);
            }

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
        });
    }

    /** الاعتماد: حجز الكميات المعتمدة في الفرع المرسل حتى لا تُباع قبل الشحن. */
    public function approve(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Draft, 'الاعتماد');
            if ($transfer->items->sum(fn ($item) => (float) ($itemsPayload[$item->id] ?? $item->requested_qty)) <= 0) {
                throw new BusinessException('يجب اعتماد كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل بدلًا من اعتماده بصفر.');
            }
            $levels = $this->lockTransferLevels($transfer, true, false);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $approvedQty = (float) ($itemsPayload[$item->id] ?? $item->requested_qty);

                if ($approvedQty < 0 || $approvedQty > (float) $item->requested_qty) {
                    throw new BusinessException('الكمية المعتمدة يجب أن تكون بين صفر والكمية المطلوبة.');
                }

                // تحديثات البنود تتجمع في UPDATE واحد بعد الحلقة (M-6) — الأقفال
                // مأخوذة طوال العملية، فتقليل الاستعلامات يقلل زمن احتجازها.
                // الصف يُمرَّر كاملًا بحالته الحالية: PostgreSQL يتحقق من قيود
                // NOT NULL وCHECK على الصف المقترح في upsert قبل حل التعارض.
                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'approved_qty' => $approvedQty, 'updated_at' => now()];

                if ($approvedQty > 0) {
                    $this->move([
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
                    ], $levels);
                }
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['approved_qty', 'updated_at']);
            }

            $transfer->update(['status' => TransferStatus::Approved, 'approved_by' => $userId]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /** الشحن: فك الحجز وإخراج البضاعة من المرسل، وتسجيلها "في الطريق" عند المستلم. */
    public function ship(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            if (in_array($transfer->status, [TransferStatus::Shipped, TransferStatus::Received], true)) {
                return $this->replayCompletedStage($transfer, $itemsPayload, 'shipped_qty');
            }
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Approved, 'الشحن');
            if ($transfer->items->sum(fn ($item) => (float) ($itemsPayload[$item->id] ?? $item->approved_qty)) <= 0) {
                throw new BusinessException('يجب شحن كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل لفك الحجز.');
            }
            $levels = $this->lockTransferLevels($transfer, true, true);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $shippedQty = (float) ($itemsPayload[$item->id] ?? $item->approved_qty);

                if ($shippedQty < 0 || $shippedQty > (float) $item->approved_qty) {
                    throw new BusinessException('الكمية المشحونة يجب أن تكون بين صفر والكمية المعتمدة.');
                }

                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'shipped_qty' => $shippedQty, 'updated_at' => now()];

                if ($shippedQty <= 0) {
                    if ((float) $item->approved_qty > 0) {
                        $this->move([
                            'product_variant_id' => $item->product_variant_id,
                            'branch_id' => $transfer->from_branch_id,
                            'location_id' => $item->from_location_id,
                            'type' => StockMovementType::ReleaseReserve,
                            'affected_bucket' => StockBucket::Reserved,
                            'quantity' => -(float) $item->approved_qty,
                            'reference_type' => StockTransfer::class,
                            'reference_id' => $transfer->id,
                            'created_by' => $userId,
                            'notes' => 'فك حجز صنف غير مشحون من تحويل '.$transfer->transfer_number,
                        ], $levels);
                    }

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
                $this->move([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::ReleaseReserve,
                    'affected_bucket' => StockBucket::Reserved,
                    'quantity' => -$shippedQty,
                    'notes' => 'فك حجز عند شحن تحويل '.$transfer->transfer_number,
                ], $levels);
                $this->move([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::TransferOut,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => -$shippedQty,
                    'notes' => 'شحن تحويل '.$transfer->transfer_number,
                ], $levels);

                // الباقي المحجوز (معتمد ولم يُشحن) يرجع متاحًا للبيع
                $unshipped = round((float) $item->approved_qty - $shippedQty, 4);
                if ($unshipped > 0) {
                    $this->move([...$base,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => -$unshipped,
                        'notes' => 'فك حجز الكمية المعتمدة غير المشحونة من تحويل '.$transfer->transfer_number,
                    ], $levels);
                }

                // المستلم: بضاعة في الطريق
                $this->move([...$base,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'type' => StockMovementType::TransferInTransit,
                    'affected_bucket' => StockBucket::InTransit,
                    'quantity' => $shippedQty,
                    'notes' => 'بضاعة في الطريق من تحويل '.$transfer->transfer_number,
                ], $levels);
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['shipped_qty', 'updated_at']);
            }

            $transfer->update(['status' => TransferStatus::Shipped, 'shipped_at' => now()]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /** الاستلام: تسوية in_transit بالكامل، وإضافة المستلم فعليًا، وتسوية أي عجز كحركة مستقلة. */
    public function receive(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->to_branch_id);
            if ($transfer->status === TransferStatus::Received) {
                return $this->replayCompletedStage($transfer, $itemsPayload, 'received_qty');
            }
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Shipped, 'الاستلام');
            $levels = $this->lockTransferLevels($transfer, false, true);

            $shortageNotes = [];
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $receivedQty = (float) ($itemsPayload[$item->id] ?? $item->shipped_qty);
                $shippedQty = (float) $item->shipped_qty;

                if ($receivedQty < 0 || $receivedQty > $shippedQty) {
                    throw new BusinessException('الكمية المستلمة يجب أن تكون بين صفر والكمية المشحونة.');
                }

                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'received_qty' => $receivedQty, 'updated_at' => now()];

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
                    $this->move([...$base,
                        'type' => StockMovementType::TransferTransitRelease,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $this->move([...$base,
                        'type' => StockMovementType::TransferIn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                }

                // العجز بين المشحون والمستلم: تسوية مستقلة موثقة من رصيد "في الطريق"
                $shortage = round($shippedQty - $receivedQty, 4);
                if ($shortage > 0) {
                    $this->move([...$base,
                        'type' => StockMovementType::TransferShortage,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$shortage,
                        'notes' => 'عجز عند استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $shortageNotes[] = "عجز {$shortage} وحدة في المتغير #{$item->product_variant_id}";
                }
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['received_qty', 'updated_at']);
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
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);

            if ($transfer->status === TransferStatus::Approved) {
                $levels = $this->lockTransferLevels($transfer, true, false);
                foreach ($transfer->items as $item) {
                    if ((float) $item->approved_qty <= 0) {
                        continue;
                    }

                    $this->move([
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
                    ], $levels);
                }
            } elseif ($transfer->status !== TransferStatus::Draft) {
                throw new BusinessException('لا يمكن إلغاء تحويل بحالة '.$transfer->status->value.'.');
            }

            $transfer->update(['status' => TransferStatus::Cancelled]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    public function delete(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId): void {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف تحويل إلا في حالة المسودة أو الملغي.');
            }

            // تحويل اعتُمد ثم أُلغي له حركات دفتر (حجز/فك حجز) تشير إليه بلا FK؛
            // حذفه يترك مراجع يتيمة ويقطع أثر التدقيق — الإلغاء نفسه هو الحفظ الصحيح.
            if (StockMovement::query()->whereIn('reference_type', [StockTransfer::class, 'stock_transfer'])->where('reference_id', $transfer->id)->exists()) {
                throw new BusinessException('لا يمكن حذف تحويل له حركات مخزون مسجلة في الدفتر؛ إلغاؤه يحفظ أثر التدقيق.');
            }

            $transfer->delete();
        });
    }

    /**
     * حماية من الحمولات القديمة: أي معرّف عنصر لا يتبع التحويل المقفول يُرفض بدل
     * تجاهله بصمت (التعديل بين تحقق الطلب والقفل كان يبدّل المعرّفات)، وطابع
     * expected_updated_at الاختياري يمنع اعتماد نسخة لم يراجعها المعتمد.
     */
    private function assertPayloadMatchesLockedItems(StockTransfer $transfer, array $itemsPayload, ?string $expectedUpdatedAt, ?int $expectedVersion = null): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $transfer->version) {
            throw new BusinessException('تم تعديل التحويل؛ أعد تحميله وراجع الكميات.', ['current_version' => $transfer->version], 409);
        }

        $unknownIds = array_diff(array_map('intval', array_keys($itemsPayload)), $transfer->items->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($unknownIds !== []) {
            throw new BusinessException(
                'توجد عناصر في الطلب لا تتبع هذا التحويل (المعرّفات: '.implode(', ', $unknownIds).'). أعد تحميل التحويل وراجع الكميات.',
                ['unknown_item_ids' => array_values($unknownIds)],
                409,
            );
        }

        if ($expectedUpdatedAt !== null
            && ! Carbon::parse($expectedUpdatedAt)->equalTo($transfer->updated_at)) {
            throw new BusinessException(
                'تم تعديل هذا التحويل بعد تحميله. أعد تحميله وراجع الكميات قبل التنفيذ.',
                ['current_updated_at' => $transfer->updated_at->toIso8601String()],
                409,
            );
        }
    }

    private function replayCompletedStage(StockTransfer $transfer, array $itemsPayload, string $quantityField): StockTransfer
    {
        $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, null);
        foreach ($transfer->items as $item) {
            if (array_key_exists($item->id, $itemsPayload)
                && (int) round((float) $itemsPayload[$item->id] * 10000) !== (int) round((float) $item->{$quantityField} * 10000)) {
                throw new BusinessException('تم تنفيذ المرحلة بكميات مختلفة؛ أعد تحميل التحويل.', [], 409);
            }
        }

        return $transfer->load(['fromBranch', 'toBranch']);
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
        $this->assertDistinctItems($items);
        foreach ($items as $item) {
            $transfer->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'from_location_id' => $item['from_location_id'] ?? null,
                'to_location_id' => $item['to_location_id'] ?? null,
                'requested_qty' => $item['requested_qty'],
            ]);
        }
    }

    private function assertDistinctItems(array $items): void
    {
        $keys = array_map(fn ($item) => $item['product_variant_id'].':'.($item['from_location_id'] ?? 0).':'.($item['to_location_id'] ?? 0), $items);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new BusinessException('لا يجوز تكرار نفس الصنف وموقعي الإرسال والاستلام داخل التحويل.');
        }
    }

    /** حركة على صف مقفول مسبقًا ضمن $levels — بلا إعادة قفل أو فحص موقع. */
    private function move(array $data, Collection $levels): void
    {
        $level = $levels->get($this->inventory->levelKey([
            'product_variant_id' => $data['product_variant_id'],
            'branch_id' => $data['branch_id'],
            'location_id' => $data['location_id'] ?? null,
        ]));

        $this->inventory->recordMovement($data, $level);
    }

    private function lockTransferLevels(StockTransfer $transfer, bool $source, bool $destination): Collection
    {
        $keys = [];
        foreach ($transfer->items as $item) {
            if ($source) {
                $keys[] = ['branch_id' => $transfer->from_branch_id, 'location_id' => $item->from_location_id, 'product_variant_id' => $item->product_variant_id];
            }
            if ($destination) {
                $keys[] = ['branch_id' => $transfer->to_branch_id, 'location_id' => $item->to_location_id, 'product_variant_id' => $item->product_variant_id];
            }
        }

        return $this->inventory->lockLevels($keys);
    }
}
