<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\Shared\SettingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Inventory\TransferStatus;
use App\Events\Inventory\TransferPendingApproval;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
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
            app(BranchAccess::class)->lockActiveBranches([$data['from_branch_id'], $data['to_branch_id']]);
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
            $this->assertDraftAccess($transfer, $userId);
            // Moving the draft across branches requires access to the new side; leaving
            // the branches untouched keeps the relaxed draft access above.
            if (array_key_exists('from_branch_id', $data) && (int) $data['from_branch_id'] !== (int) $transfer->from_branch_id) {
                app(BranchAccess::class)->assertBranch($userId, (int) $data['from_branch_id']);
            }
            if (array_key_exists('to_branch_id', $data) && (int) $data['to_branch_id'] !== (int) $transfer->to_branch_id) {
                app(BranchAccess::class)->assertBranch($userId, (int) $data['to_branch_id']);
            }

            $fromBranch = $data['from_branch_id'] ?? $transfer->from_branch_id;
            $toBranch = $data['to_branch_id'] ?? $transfer->to_branch_id;
            if ((int) $fromBranch === (int) $toBranch) {
                throw new BusinessException('يجب اختلاف الفرع المرسل عن الفرع المستلم.');
            }
            app(BranchAccess::class)->lockActiveBranches([$fromBranch, $toBranch]);
            $items = $data['items'] ?? $transfer->items->toArray();
            foreach ($items as $item) {
                foreach (['from_location_id' => $fromBranch, 'to_location_id' => $toBranch] as $field => $branchId) {
                    if (isset($item[$field]) && ! Location::query()->whereKey($item[$field])->where('branch_id', $branchId)
                        ->where('is_active', true)->exists()) {
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
            if ($transfer->status === TransferStatus::Approved) {
                // Retry after a timeout: same quantities replay the stage, different ones conflict.
                return $this->replayCompletedStage($transfer, $itemsPayload, 'approved_qty');
            }
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Draft, 'الاعتماد');
            // Early validation prevents approving transfers targeted at inactive or unreceivable branches.
            $this->assertReceivableDestination($transfer);
            // Resolve and lock the final destination before any reservation is posted.
            // This makes duplicate detection use the real location while the draft can
            // still be corrected without leaving inventory reserved indefinitely.
            $this->assignReceivingLocation($transfer);
            $totalApproved = $transfer->items->reduce(
                fn (BigDecimal $sum, $item) => $sum->plus($this->quantity($itemsPayload[$item->id] ?? $item->requested_qty)),
                BigDecimal::of('0'),
            );
            if (! $totalApproved->isPositive()) {
                throw new BusinessException('يجب اعتماد كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل بدلًا من اعتماده بصفر.');
            }
            $levels = $this->lockTransferLevels($transfer, true, true);
            $this->assertLockedDestinationsActive($transfer, $levels);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $approvedQty = $this->quantity($itemsPayload[$item->id] ?? $item->requested_qty);

                if ($approvedQty->isNegative() || $approvedQty->compareTo($this->quantity($item->requested_qty)) > 0) {
                    throw new BusinessException('الكمية المعتمدة يجب أن تكون بين صفر والكمية المطلوبة.');
                }

                // Collect quantity updates into a single batch upsert to minimize lock holding duration.
                $qtyUpdates[] = $this->itemUpsertRow($item, 'approved_qty', $approvedQty);

                if ($approvedQty->isPositive()) {
                    $this->move([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::Reserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => (string) $approvedQty,
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
            $totalShipped = $transfer->items->reduce(
                fn (BigDecimal $sum, $item) => $sum->plus($this->quantity($itemsPayload[$item->id] ?? $item->approved_qty)),
                BigDecimal::of('0'),
            );
            if (! $totalShipped->isPositive()) {
                throw new BusinessException('يجب شحن كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل لفك الحجز.');
            }
            $this->assignReceivingLocation($transfer);
            $levels = $this->lockTransferLevels($transfer, true, true);
            $this->assertLockedDestinationsActive($transfer, $levels);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $shippedQty = $this->quantity($itemsPayload[$item->id] ?? $item->approved_qty);

                if ($shippedQty->isNegative() || $shippedQty->compareTo($this->quantity($item->approved_qty)) > 0) {
                    throw new BusinessException('الكمية المشحونة يجب أن تكون بين صفر والكمية المعتمدة.');
                }

                $qtyUpdates[] = $this->itemUpsertRow($item, 'shipped_qty', $shippedQty);

                if (! $shippedQty->isPositive()) {
                    if ($this->quantity($item->approved_qty)->isPositive()) {
                        $this->move([
                            'product_variant_id' => $item->product_variant_id,
                            'branch_id' => $transfer->from_branch_id,
                            'location_id' => $item->from_location_id,
                            'type' => StockMovementType::ReleaseReserve,
                            'affected_bucket' => StockBucket::Reserved,
                            'quantity' => (string) $this->quantity($item->approved_qty)->negated(),
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
                    'quantity' => (string) $shippedQty->negated(),
                    'notes' => 'فك حجز عند شحن تحويل '.$transfer->transfer_number,
                ], $levels);
                $this->move([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::TransferOut,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => (string) $shippedQty->negated(),
                    'notes' => 'شحن تحويل '.$transfer->transfer_number,
                ], $levels);

                // Release approved but unshipped quantity back to available inventory
                $unshipped = $this->quantity($item->approved_qty)->minus($shippedQty);
                if ($unshipped->isPositive()) {
                    $this->move([...$base,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => (string) $unshipped->negated(),
                        'notes' => 'فك حجز الكمية المعتمدة غير المشحونة من تحويل '.$transfer->transfer_number,
                    ], $levels);
                }

                // Increment in-transit stock at the destination branch/location
                $this->move([...$base,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'type' => StockMovementType::TransferInTransit,
                    'affected_bucket' => StockBucket::InTransit,
                    'quantity' => (string) $shippedQty,
                    'notes' => 'بضاعة في الطريق من تحويل '.$transfer->transfer_number,
                ], $levels);
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['to_location_id', 'shipped_qty', 'updated_at']);
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
            $hasShortage = $transfer->items->contains(fn ($item) => $this->quantity($item->shipped_qty)
                ->compareTo($this->quantity($itemsPayload[$item->id] ?? $item->shipped_qty)) > 0);
            if ($hasShortage && ! User::query()->findOrFail($userId)->can('transfers.approve_shortage')) {
                throw new BusinessException('تسجيل عجز عند الاستلام يتطلب صلاحية اعتماد العجز.', [], 403);
            }
            // Older transfers can have transit stock on the branch-level row.
            $inactiveLocations = Location::query()->whereKey($transfer->items->pluck('to_location_id')->filter()->unique()->all())
                ->where('is_active', false)->pluck('id')->flip();
            $needsReceivingLocation = $transfer->items->contains(fn ($item) =>
                ($item->to_location_id === null || $inactiveLocations->has($item->to_location_id))
                && $this->quantity($itemsPayload[$item->id] ?? $item->shipped_qty)->isPositive());
            $receivingLocation = $needsReceivingLocation ? $this->lockReceivingLocation($transfer->to_branch_id) : null;
            if ($receivingLocation === null && $transfer->items->contains(fn ($item) =>
                $inactiveLocations->has($item->to_location_id)
                && $this->quantity($itemsPayload[$item->id] ?? $item->shipped_qty)->isPositive())) {
                throw new BusinessException('لا يوجد موقع استلام نشط؛ فعّل موقعًا للاستلام ثم أعد المحاولة.');
            }
            $levels = $this->lockTransferLevels($transfer, false, true, $receivingLocation, $inactiveLocations);

            $shortageNotes = [];
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $receivedQty = $this->quantity($itemsPayload[$item->id] ?? $item->shipped_qty);
                $shippedQty = $this->quantity($item->shipped_qty);

                if ($receivedQty->isNegative() || $receivedQty->compareTo($shippedQty) > 0) {
                    throw new BusinessException('الكمية المستلمة يجب أن تكون بين صفر والكمية المشحونة.');
                }

                if (! $shippedQty->isPositive()) {
                    $qtyUpdates[] = $this->itemUpsertRow($item, 'received_qty', $receivedQty);
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
                if ($receivedQty->isPositive()) {
                    $receiptLocationId = $inactiveLocations->has($item->to_location_id)
                        ? $receivingLocation
                        : ($item->to_location_id ?? $receivingLocation);
                    $this->move([...$base,
                        'type' => StockMovementType::TransferTransitRelease,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => (string) $receivedQty->negated(),
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $this->move([...$base,
                        'location_id' => $receiptLocationId,
                        'type' => StockMovementType::TransferIn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => (string) $receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);

                    // Persist the fallback location alongside the receipt movement. Older
                    // branch-level transit rows have no location, but the accepted stock
                    // now belongs to this concrete destination location.
                    if (($item->to_location_id === null || $inactiveLocations->has($item->to_location_id))
                        && ! $this->hasSiblingAtLocation($transfer, $item, $receivingLocation, $inactiveLocations)) {
                        $item->to_location_id = $receivingLocation;
                    }
                    $item->received_location_id = $receiptLocationId;
                }

                $qtyUpdates[] = $this->itemUpsertRow($item, 'received_qty', $receivedQty);

                // Any shortage is deducted from in-transit as an explicit shortage movement for loss tracking
                $shortage = $shippedQty->minus($receivedQty);
                if ($shortage->isPositive()) {
                    $this->move([...$base,
                        'type' => StockMovementType::TransferShortage,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => (string) $shortage->negated(),
                        'notes' => 'عجز عند استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $shortageNotes[] = "عجز {$shortage} وحدة في المتغير #{$item->product_variant_id}";
                }
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['to_location_id', 'received_location_id', 'received_qty', 'updated_at']);
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
            $actor = User::query()->findOrFail($userId);
            $hasLedgerHistory = StockMovement::query()
                ->whereIn('reference_type', [(new StockTransfer)->getMorphClass(), StockTransfer::class])
                ->where('reference_id', $transfer->id)
                ->exists();

            if ($transfer->status === TransferStatus::Cancelled) {
                // Retry after a timeout: a completed cancellation returns the entity as-is.
                // Same access rule as the draft it was cancelled from, so a destination-side
                // retry does not fail after the first cancellation succeeded.
                abort_unless($actor->can($hasLedgerHistory ? 'transfers.approve' : 'transfers.update'), 403);
                if ($hasLedgerHistory) {
                    app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
                } else {
                    $this->assertDraftAccess($transfer, $userId);
                }

                return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
            }

            if ($transfer->status === TransferStatus::Draft) {
                abort_unless($actor->can('transfers.update'), 403);
                $this->assertDraftAccess($transfer, $userId);
            } else {
                abort_unless($actor->can('transfers.approve'), 403);
                app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            }

            if ($transfer->status === TransferStatus::Approved) {
                $levels = $this->lockTransferLevels($transfer, true, false);
                foreach ($transfer->items as $item) {
                    if (! $this->quantity($item->approved_qty)->isPositive()) {
                        continue;
                    }

                    $this->move([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => (string) $this->quantity($item->approved_qty)->negated(),
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
            $hasLedgerHistory = StockMovement::query()
                ->whereIn('reference_type', [(new StockTransfer)->getMorphClass(), StockTransfer::class])
                ->where('reference_id', $transfer->id)
                ->exists();
            if (in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Cancelled], true) && ! $hasLedgerHistory) {
                $this->assertDraftAccess($transfer, $userId);
            } else {
                app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            }

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
        $defaultField = match ($quantityField) {
            'approved_qty' => 'requested_qty',
            'shipped_qty' => 'approved_qty',
            'received_qty' => 'shipped_qty',
            default => throw new \LogicException('Unsupported transfer replay quantity field.'),
        };
        foreach ($transfer->items as $item) {
            $requestedQty = $itemsPayload[$item->id] ?? $item->{$defaultField};
            if ($this->quantity($requestedQty)->compareTo($this->quantity($item->{$quantityField})) !== 0) {
                throw new BusinessException('تم تنفيذ المرحلة بكميات مختلفة؛ أعد تحميل التحويل.', [], 409);
            }
        }

        return $transfer->load(['fromBranch', 'toBranch']);
    }

    /**
     * Authorize draft changes from the source branch, the destination branch, or the creator.
     *
     * A draft initiated by the destination store manager must remain manageable by that
     * side: the creator or anyone with destination-branch access may edit or cancel it.
     */
    private function assertDraftAccess(StockTransfer $transfer, int $userId): void
    {
        if ((int) $transfer->created_by === $userId || $this->hasBranchAccess($userId, (int) $transfer->to_branch_id)) {
            return;
        }
        app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
    }

    private function hasBranchAccess(int $userId, int $branchId): bool
    {
        try {
            app(BranchAccess::class)->assertBranch($userId, $branchId);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
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
        $toBranch = Branch::query()->find($transfer->to_branch_id);
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

        // Single query for all destination locations instead of one EXISTS per item.
        $toLocationIds = $transfer->items->map(fn ($item) => $item->to_location_id)
            ->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($toLocationIds !== []) {
            $validLocationIds = Location::query()->whereIn('id', $toLocationIds)
                ->where('branch_id', $transfer->to_branch_id)->where('is_active', true)
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($validLocationIds) !== count($toLocationIds)) {
                throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع الوجهة.');
            }
        }
    }

    /**
     * @param  list<array{product_variant_id: int, from_location_id?: int|null, to_location_id?: int|null, requested_qty: float|int|string}>  $items
     */
    private function syncItems(StockTransfer $transfer, array $items): void
    {
        $receivingLocation = collect($items)->contains(fn ($item) => empty($item['to_location_id']))
            ? $this->lockReceivingLocation($transfer->to_branch_id)
            : null;
        if ($receivingLocation !== null) {
            foreach ($items as &$item) {
                $item['to_location_id'] ??= $receivingLocation;
            }
            unset($item);
        }
        $this->assertDistinctItems($items);
        $locationIds = collect($items)->flatMap(fn ($item) => [$item['from_location_id'] ?? null, $item['to_location_id'] ?? null])
            ->filter()->unique()->values();
        $locations = Location::query()->whereKey($locationIds->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($items as $item) {
            foreach (['from', 'to'] as $side) {
                $locationId = $item[$side.'_location_id'] ?? null;
                if ($locationId !== null) {
                    $location = $locations->get($locationId);
                    if (! $location?->is_active || (int) $location->branch_id !== (int) $transfer->{$side.'_branch_id'}) {
                        throw new BusinessException('موقع التحويل غير نشط أو لا ينتمي إلى الفرع المحدد.');
                    }
                }
            }
            $transfer->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'from_location_id' => $item['from_location_id'] ?? null,
                'to_location_id' => $item['to_location_id'] ?? null,
                'requested_qty' => $item['requested_qty'],
            ]);
        }
    }

    private function lockReceivingLocation(int $branchId): ?int
    {
        $hasLocations = Location::query()->where('branch_id', $branchId)->exists();
        $id = Location::query()->where('branch_id', $branchId)->where('is_active', true)->where('type', '!=', 'damaged')
            ->orderByRaw("CASE WHEN type = 'receiving' THEN 0 ELSE 1 END")
            ->orderBy('id')->value('id');

        if ($id === null && $hasLocations) {
            $hasActiveLocation = Location::query()->where('branch_id', $branchId)->where('is_active', true)->exists();
            if (! $hasActiveLocation) {
                throw new BusinessException('الموقع متوقف حالياً ولا يقبل حركات مخزنية.');
            }
            throw new BusinessException('لا يوجد موقع استلام صالح للفرع.');
        }

        return $id === null ? null : (int) $id;
    }

    private function assertDistinctItems(array $items): void
    {
        $keys = array_map(fn ($item) => $item['product_variant_id'].':'.($item['from_location_id'] ?? 0).':'.($item['to_location_id'] ?? 0), $items);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new BusinessException('لا يجوز تكرار نفس الصنف وموقعي الإرسال والاستلام داخل التحويل.');
        }
    }

    private function hasSiblingAtLocation(StockTransfer $transfer, StockTransferItem $item, ?int $locationId, Collection $inactiveLocations): bool
    {
        return $locationId !== null && $transfer->items->contains(fn (StockTransferItem $candidate): bool =>
            $candidate->id !== $item->id
            && (int) $candidate->product_variant_id === (int) $item->product_variant_id
            && (int) ($candidate->from_location_id ?? 0) === (int) ($item->from_location_id ?? 0)
            && ($candidate->to_location_id === null
                || (int) $candidate->to_location_id === $locationId
                || $inactiveLocations->has($candidate->to_location_id)));
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

    /**
     * @return array<string, mixed>
     */
    private function itemUpsertRow(StockTransferItem $item, string $qtyField, BigDecimal $qty): array
    {
        return [
            ...$item->only([
                'id', 'stock_transfer_id', 'product_variant_id',
                'from_location_id', 'to_location_id', 'received_location_id', 'requested_qty',
                'approved_qty', 'shipped_qty', 'received_qty', 'created_at',
            ]),
            $qtyField => (string) $qty,
            'updated_at' => now(),
        ];
    }

    private function quantity(int|float|string $quantity): BigDecimal
    {
        return BigDecimal::of((string) $quantity)->toScale(4, RoundingMode::HalfUp);
    }

    private function assignReceivingLocation(StockTransfer $transfer): void
    {
        $unlocated = $transfer->items->filter(fn ($item) => $item->to_location_id === null);
        if ($unlocated->isEmpty()) {
            return;
        }
        $receivingLocation = $this->lockReceivingLocation($transfer->to_branch_id);
        if ($receivingLocation === null) {
            return;
        }
        foreach ($unlocated as $item) {
            $item->to_location_id = $receivingLocation;
        }
        $this->assertDistinctItems($transfer->items->toArray());
        foreach ($unlocated as $item) {
            $item->save();
        }
    }

    private function lockTransferLevels(StockTransfer $transfer, bool $source, bool $destination, ?int $legacyReceivingLocation = null, ?Collection $inactiveLocations = null): Collection
    {
        $keys = [];
        foreach ($transfer->items as $item) {
            if ($source) {
                $keys[] = ['branch_id' => $transfer->from_branch_id, 'location_id' => $item->from_location_id, 'product_variant_id' => $item->product_variant_id];
            }
            if ($destination) {
                $key = ['branch_id' => $transfer->to_branch_id, 'location_id' => $item->to_location_id, 'product_variant_id' => $item->product_variant_id];
                $keys[] = $key;
                if ($legacyReceivingLocation !== null
                    && ($item->to_location_id === null || $inactiveLocations?->has($item->to_location_id))) {
                    $keys[] = [...$key, 'location_id' => $legacyReceivingLocation];
                }
            }
        }

        return $this->inventory->lockLevels($keys);
    }

    private function assertLockedDestinationsActive(StockTransfer $transfer, Collection $levels): void
    {
        foreach ($transfer->items as $item) {
            if ($item->to_location_id === null) {
                continue;
            }
            $level = $levels->get($this->inventory->levelKey([
                'branch_id' => $transfer->to_branch_id,
                'location_id' => $item->to_location_id,
                'product_variant_id' => $item->product_variant_id,
            ]));
            if ($level?->location === null || ! $level->location->is_active) {
                throw new BusinessException('موقع الاستلام غير نشط؛ اختر موقع استلام نشطًا قبل الشحن.');
            }
        }
    }
}
