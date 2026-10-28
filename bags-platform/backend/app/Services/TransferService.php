<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Events\TransferPendingApproval;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Concerns\HandlesIdempotentCreation;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inter-branch inventory transfer service managing draft, approval, dispatch, and receiving stages.
 *
 * All inventory ledger side effects are coordinated exclusively via InventoryService:
 * - draft: No inventory side effects.
 * - approve: Reserves approved quantities at source branch to prevent concurrent overselling.
 * - ship: Releases source reserve, issues TransferOut from on_hand, and records TransferInTransit at destination.
 * - receive: Clears TransferTransitRelease from in_transit, adds TransferIn to on_hand, and writes off shortages.
 */
class TransferService
{
    use HandlesIdempotentCreation;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    /**
     * Paginates inter-branch transfers filtered by branch and status.
     *
     * @param  array<string, mixed>  $filters
     */
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

    /**
     * Retrieves a single stock transfer with items, branches, and audit relations.
     */
    public function find(int $id): StockTransfer
    {
        return StockTransfer::query()
            ->with(['items.variant', 'items.fromLocation', 'items.toLocation', 'fromBranch', 'toBranch', 'creator', 'approver'])
            ->findOrFail($id);
    }

    /**
     * Creates a new stock transfer draft with optional idempotency protection.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId, ?string $idempotencyKey = null): StockTransfer
    {
        // Transfers may be initiated from source (warehouse dispatch) or destination branch (POS replenishment request).
        $this->assertCreateAccess($userId, (int) $data['from_branch_id'], (int) $data['to_branch_id']);

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

    /**
     * Persists new draft transfer and dispatches approval notification event.
     *
     * @param  array<string, mixed>  $data
     */
    private function doCreate(array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $this->assertCreateAccess($userId, (int) $data['from_branch_id'], (int) $data['to_branch_id']);
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

    /**
     * Updates transfer header and item lines while in draft status under pessimistic row lock.
     *
     * @param  array<string, mixed>  $data
     */
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

    /**
     * Approves transfer lines and reserves inventory at source branch to guarantee shipment stock.
     *
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to approved quantity.
     */
    public function approve(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Draft, 'الاعتماد');
            // Early validation prevents approving transfers targeted at inactive or unreceivable branches.
            $this->assertReceivableDestination($transfer);
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

                // Collect quantity updates into a single batch upsert to minimize lock holding duration.
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

    /**
     * Dispatch approved stock from source branch and move into destination in-transit bucket.
     *
     * Releases source reservations, deducts physical on-hand inventory, returns any unshipped
     * approved quantity back to available stock, and registers items as in-transit at destination.
     *
     * @param  StockTransfer  $transfer
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to shipped quantity
     * @param  int  $userId
     * @param  string|null  $expectedUpdatedAt
     * @param  int|null  $expectedVersion
     * @return StockTransfer
     *
     * @throws BusinessException
     */
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
            // Fail early if destination branch or locations were deactivated between approval and shipping
            $this->assertReceivableDestination($transfer);
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

                // Release source reservation before recording physical deduction from on-hand
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

                // Release approved but unshipped quantity back to available inventory
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

                // Increment in-transit stock at the destination branch/location
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

    /**
     * Intake shipped inventory at the destination branch and settle in-transit stock.
     *
     * Moves verified goods from in-transit to on-hand. Any difference between shipped
     * and received quantity is deducted as an explicit shortage ledger movement.
     *
     * @param  StockTransfer  $transfer
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to received quantity
     * @param  int  $userId
     * @param  string|null  $expectedUpdatedAt
     * @param  int|null  $expectedVersion
     * @return StockTransfer
     *
     * @throws BusinessException
     */
    public function receive(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->to_branch_id);
            // Verify destination branch remains active before accepting inbound goods
            $toBranch = Branch::query()->find($transfer->to_branch_id);
            if ($toBranch === null || ! $toBranch->is_active) {
                throw new BusinessException('الفرع المستلم غير نشط؛ لا يمكن الاستلام إليه.');
            }
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

                // Deduct accepted quantity from in-transit and post directly to destination on-hand
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

                // Any shortage is deducted from in-transit as an explicit shortage movement for loss tracking
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

    /**
     * Cancel an active transfer in draft or approved status.
     *
     * Once approved, cancelling releases reserved inventory back to available stock.
     * Shipped transfers cannot be cancelled and must proceed to intake/settlement.
     *
     * @param  StockTransfer  $transfer
     * @param  int  $userId
     * @return StockTransfer
     *
     * @throws BusinessException
     */
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

    /**
     * Delete a draft or cancelled transfer.
     *
     * Transfers that have associated inventory ledger movements cannot be deleted
     * to preserve audit trails and prevent orphaned reference records.
     *
     * @param  StockTransfer  $transfer
     * @param  int  $userId
     * @return void
     *
     * @throws BusinessException
     */
    public function delete(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId): void {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف تحويل إلا في حالة المسودة أو الملغي.');
            }

            // Transfers with posted ledger movements must be preserved for financial and audit trails
            if (StockMovement::query()->whereIn('reference_type', [StockTransfer::class, 'stock_transfer'])->where('reference_id', $transfer->id)->exists()) {
                throw new BusinessException('لا يمكن حذف تحويل له حركات مخزون مسجلة في الدفتر؛ إلغاؤه يحفظ أثر التدقيق.');
            }

            $transfer->delete();
        });
    }

    /**
     * Prevent stale submission payloads and concurrent overwrites.
     *
     * Validates item IDs against locked items to catch deletions/swaps between request parsing
     * and lock acquisition, and verifies optimistic concurrency controls (version and updated_at).
     */
    private function assertPayloadMatchesLockedItems(StockTransfer $transfer, array $itemsPayload, ?string $expectedUpdatedAt, ?int $expectedVersion = null): void
    {
        if ($expectedUpdatedAt !== null && $expectedVersion === null) {
            throw new BusinessException('أعد تحميل التحويل لإرسال رقم الإصدار قبل التنفيذ.', [], 409);
        }
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

    /**
     * Authorize transfer initiation from either source or destination branch.
     *
     * Transfers can be initiated by source warehouse dispatchers or destination store managers.
     */
    private function assertCreateAccess(int $userId, int $fromBranchId, int $toBranchId): void
    {
        try {
            app(BranchAccess::class)->assertBranch($userId, $fromBranchId);
        } catch (AuthorizationException) {
            app(BranchAccess::class)->assertBranch($userId, $toBranchId);
        }
    }

    private function assertStatus(StockTransfer $transfer, TransferStatus $expected, string $action): void
    {
        if ($transfer->status !== $expected) {
            throw new BusinessException("لا يمكن تنفيذ {$action} على تحويل بحالة {$transfer->status->value}.");
        }
    }

    /**
     * Validate destination branch, locations, and variants before dispatch.
     *
     * Prevents inventory from getting trapped in transit if the destination branch
     * or receiving location was archived or deactivated after transfer creation.
     */
    private function assertReceivableDestination(StockTransfer $transfer): void
    {
        $toBranch = Branch::query()->sharedLock()->find($transfer->to_branch_id);
        if ($toBranch === null || ! $toBranch->is_active) {
            throw new BusinessException('الفرع الوجهة غير نشط؛ لا يمكن اعتماد أو شحن التحويل إليه.');
        }

        $variantIds = $transfer->items->map(fn ($item) => (int) $item->product_variant_id)->unique()->values()->all();
        $existingVariantIds = ProductVariant::withTrashed()->whereIn('id', $variantIds)
            ->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_diff($variantIds, $existingVariantIds);
        if ($missing !== []) {
            throw new BusinessException('أحد أصناف التحويل غير نشط أو لم يعد موجودًا؛ راجع البنود قبل المتابعة.');
        }

        foreach ($transfer->items as $item) {
            if ($item->to_location_id !== null && ! Location::query()->whereKey($item->to_location_id)
                ->where('branch_id', $transfer->to_branch_id)->where('is_active', true)->exists()) {
                throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع الوجهة.');
            }
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

    /**
     * Post movement using pre-locked stock level instances to bypass re-locking overhead.
     */
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
