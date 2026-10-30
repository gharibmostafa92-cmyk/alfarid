<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\SupplierDeliveryStatus;
use App\Events\PurchaseOrderSentToSupplier;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Models\SupplierDeliveryItem;
use App\Models\SupplierUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manages the complete procurement lifecycle:
 * Internal purchase requests (branch) -> approval -> purchase orders -> vendor dispatch ->
 * supplier portal acceptance / revision requests -> advance shipping notices (ASN) -> warehouse intake.
 *
 * Updates company-wide weighted average cost (WAC) per variant on goods receipt before inventory ledger posting:
 *   NewAvg = (CurrentTotalOnHand * OldCost + ReceivedQty * PurchaseCost) / (CurrentTotalOnHand + ReceivedQty)
 * where CurrentTotalOnHand comprises total on-hand and in-transit units across all branches.
 */
class ProcurementService
{
    public function __construct(
        protected InventoryService $inventory,
        protected SettingService $settings,
    ) {}

    // ================= Internal Purchase Requests =================

    /**
     * Create a draft internal purchase request for a branch.
     *
     * @param  array{branch_id: int, notes?: string|null, items: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
     * @param  User  $requester
     * @return PurchaseRequest
     */
    public function createPurchaseRequest(array $data, User $requester): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $requester): PurchaseRequest {
            $pr = PurchaseRequest::create([
                'request_number' => $this->settings->generateNextNumber('purchase_request'),
                'branch_id' => (int) $data['branch_id'],
                'requested_by' => $requester->id,
                'status' => PurchaseRequestStatus::Draft,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->syncRequestItems($pr, $data['items']);

            return $pr->fresh(['items']);
        });
    }

    /**
     * Update an unapproved draft purchase request.
     *
     * @param  PurchaseRequest  $pr
     * @param  array{branch_id?: int, notes?: string|null, items?: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
     * @return PurchaseRequest
     *
     * @throws BusinessException
     */
    public function updatePurchaseRequest(PurchaseRequest $pr, array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $data): PurchaseRequest {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequestStatus::Draft) {
                throw new BusinessException('لا يمكن تعديل طلب شراء إلا وهو مسودة.');
            }
            $pr->update(array_intersect_key($data, array_flip(['branch_id', 'notes'])));
            if (isset($data['items'])) {
                $pr->items()->delete();
                $this->syncRequestItems($pr, $data['items']);
            }

            return $pr->fresh(['items']);
        });
    }

    /**
     * Submit a draft purchase request for management approval.
     *
     * @param  PurchaseRequest  $pr
     * @return PurchaseRequest
     */
    public function submitPurchaseRequest(PurchaseRequest $pr): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending, 'الإرسال');
    }

    /**
     * Approve a pending purchase request, making it eligible for PO conversion.
     *
     * @param  PurchaseRequest  $pr
     * @return PurchaseRequest
     */
    public function approvePurchaseRequest(PurchaseRequest $pr): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Pending, PurchaseRequestStatus::Approved, 'الاعتماد');
    }

    /**
     * Reject a pending purchase request.
     *
     * @param  PurchaseRequest  $pr
     * @return PurchaseRequest
     */
    public function rejectPurchaseRequest(PurchaseRequest $pr): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Pending, PurchaseRequestStatus::Rejected, 'الرفض');
    }

    private function transitionRequest(PurchaseRequest $pr, PurchaseRequestStatus $from, PurchaseRequestStatus $to, string $action): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $from, $to, $action): PurchaseRequest {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== $from) {
                throw new BusinessException("لا يمكن {$action} طلب شراء في حالة ({$pr->status->value}).");
            }
            $pr->update(['status' => $to]);

            return $pr->fresh(['items']);
        });
    }

    /**
     * @param  PurchaseRequest  $pr
     * @param  list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>  $items
     * @return void
     */
    private function syncRequestItems(PurchaseRequest $pr, array $items): void
    {
        foreach ($items as $item) {
            DiscreteQuantity::assert($item['requested_qty']);
            $pr->items()->create([
                'product_variant_id' => (int) $item['product_variant_id'],
                'requested_qty' => (float) $item['requested_qty'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    // ================= Purchase Orders =================

    /**
     * Create a draft purchase order, optionally converting an approved internal request.
     *
     * When linked to a purchase request, items are automatically populated from the request
     * using current variant cost prices, and the request transitions to converted_to_po.
     *
     * @param  array{supplier_id: int, branch_id?: int|null, purchase_request_id?: int|null, expected_delivery_date?: string|null, discount_amount?: float|int|string|null, items?: list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>}  $data
     * @param  User  $creator
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function createPurchaseOrder(array $data, User $creator): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $creator): PurchaseOrder {
            $pr = null;
            if (! empty($data['purchase_request_id'])) {
                $pr = PurchaseRequest::query()->lockForUpdate()->find((int) $data['purchase_request_id']);
                if ($pr === null) {
                    throw new BusinessException('طلب الشراء الداخلي المرتبط غير موجود.');
                }
                if ($pr->status !== PurchaseRequestStatus::Approved) {
                    throw new BusinessException('لا يمكن إنشاء أمر شراء إلا من طلب داخلي معتمد.');
                }
                $pr->load('items.variant');
            }

            $items = $data['items'] ?? [];
            if ($items === [] && $pr !== null) {
                $items = $pr->items->map(fn ($item): array => [
                    'product_variant_id' => (int) $item->product_variant_id,
                    'ordered_qty' => (float) $item->requested_qty,
                    'unit_cost' => (float) ($item->variant?->cost_price ?? 0),
                    'tax_rate' => 0,
                ])->all();
            }
            if ($items === []) {
                throw new BusinessException('أمر الشراء يحتاج بندًا واحدًا على الأقل.');
            }

            $branchId = (int) ($data['branch_id'] ?? $pr?->branch_id ?? 0);
            if ($branchId <= 0) {
                throw new BusinessException('حدد فرع/مخزن استلام أمر الشراء.');
            }

            if (! Supplier::query()->whereKey($data['supplier_id'])->where('is_active', true)->exists()
                || ! Branch::query()->whereKey($branchId)->where('is_active', true)->exists()) {
                throw new BusinessException('المورد وفرع الاستلام يجب أن يكونا نشطين.');
            }
            $po = PurchaseOrder::create([
                'po_number' => $this->settings->generateNextNumber('purchase_order'),
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => $branchId,
                'purchase_request_id' => $pr?->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'discount_amount' => (float) ($data['discount_amount'] ?? 0),
                'created_by' => $creator->id,
            ]);
            $this->syncOrderItems($po, $items);
            $this->recalculateTotals($po);

            $pr?->update(['status' => PurchaseRequestStatus::ConvertedToPo]);

            return $po->fresh(['items']);
        });
    }

    /**
     * Update an unreceived draft or revision-requested purchase order.
     *
     * @param  PurchaseOrder  $po
     * @param  array{supplier_id?: int, branch_id?: int, expected_delivery_date?: string|null, discount_amount?: float|int|string|null, items?: list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>}  $data
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function updatePurchaseOrder(PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data): PurchaseOrder {
            $po = $this->lockOrder($po);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException('لا يمكن تعديل أمر شراء إلا وهو مسودة أو بانتظار معالجة تعديلات المورد.');
            }
            if ($po->items()->where('received_qty', '>', 0)->exists()) {
                throw new BusinessException('أمر شراء بدأ استلامه لا تُعدَّل بنوده.');
            }
            if ($po->deliveries()->exists()
                && (isset($data['items'])
                    || (isset($data['supplier_id']) && (int) $data['supplier_id'] !== (int) $po->supplier_id)
                    || (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $po->branch_id))) {
                throw new BusinessException('لا يمكن تغيير المورد أو الفرع أو البنود بعد تسجيل إشعار توريد.');
            }
            // Modifying core commercial terms invalidates prior internal approval
            if (array_intersect(['supplier_id', 'branch_id', 'items', 'discount_amount', 'expected_delivery_date'], array_keys($data))) {
                $data['approved_by'] = null;
            }
            if (isset($data['supplier_id']) && ! Supplier::query()->whereKey($data['supplier_id'])->where('is_active', true)->exists()) {
                throw new BusinessException('المورد غير نشط.');
            }
            if (isset($data['branch_id']) && ! Branch::query()->whereKey($data['branch_id'])->where('is_active', true)->exists()) {
                throw new BusinessException('فرع الاستلام غير نشط.');
            }
            $po->update(array_intersect_key($data, array_flip([
                'supplier_id', 'branch_id', 'expected_delivery_date', 'discount_amount', 'approved_by',
            ])));
            if (isset($data['items'])) {
                $po->items()->delete();
                $this->syncOrderItems($po, $data['items']);
            }
            $this->recalculateTotals($po);

            return $po->fresh(['items']);
        });
    }

    /**
     * Approve a purchase order internally (separation of duties enforced unless self-approval is enabled).
     *
     * @param  PurchaseOrder  $po
     * @param  User  $admin
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function approvePurchaseOrder(PurchaseOrder $po, User $admin): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $admin): PurchaseOrder {
            $po = $this->lockOrder($po);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException('لا يمكن اعتماد أمر شراء إلا وهو مسودة أو بانتظار معالجة التعديلات.');
            }
            if ((int) $po->created_by === (int) $admin->id
                && ! (bool) $this->settings->get('allow_self_approve_purchases', false)) {
                throw new BusinessException('لا يجوز لمن أنشأ أمر الشراء اعتماده بنفسه.');
            }
            $po->update(['approved_by' => $admin->id]);

            return $po->fresh(['items']);
        });
    }

    /**
     * Dispatch an approved purchase order to the vendor and fire notification event.
     *
     * @param  PurchaseOrder  $po
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function sendPurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po): PurchaseOrder {
            $po = $this->lockOrder($po);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException("لا يمكن إرسال أمر شراء في حالة ({$po->status->value}).");
            }
            if ($po->approved_by === null) {
                throw new BusinessException('يجب اعتماد أمر الشراء قبل إرساله إلى المورد.');
            }
            if ($po->items()->count() === 0) {
                throw new BusinessException('لا يمكن إرسال أمر شراء بلا بنود.');
            }
            if (! Supplier::query()->whereKey($po->supplier_id)->where('is_active', true)->exists()) {
                throw new BusinessException('المورد غير نشط.');
            }
            $po->update(['status' => PurchaseOrderStatus::SentToSupplier, 'supplier_change_notes' => null]);

            $sent = $po->fresh(['items']);
            PurchaseOrderSentToSupplier::dispatch($sent);

            return $sent;
        });
    }

    /**
     * Cancel an active purchase order and reject any pending vendor delivery notices.
     *
     * @param  PurchaseOrder  $po
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function cancelPurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po): PurchaseOrder {
            $po = $this->lockOrder($po);
            if ($po->items()->where('received_qty', '>', 0)->exists()) {
                throw new BusinessException('لا يمكن إلغاء أمر شراء بدأ استلام بضاعته.');
            }
            if (! in_array($po->status, [
                PurchaseOrderStatus::Draft, PurchaseOrderStatus::SentToSupplier,
                PurchaseOrderStatus::SupplierAccepted, PurchaseOrderStatus::ChangeRequested,
            ], true)) {
                throw new BusinessException("لا يمكن إلغاء أمر شراء في حالة ({$po->status->value}).");
            }
            $po->update(['status' => PurchaseOrderStatus::Cancelled]);
            $po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->update(['status' => SupplierDeliveryStatus::Rejected]);
            if ($po->purchase_request_id !== null) {
                PurchaseRequest::query()->whereKey($po->purchase_request_id)->where('status', PurchaseRequestStatus::ConvertedToPo)->update(['status' => PurchaseRequestStatus::Approved]);
            }

            return $po->fresh(['items']);
        });
    }

    /**
     * @param  PurchaseOrder  $po
     * @param  list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>  $items
     * @return void
     */
    private function syncOrderItems(PurchaseOrder $po, array $items): void
    {
        foreach ($items as $item) {
            DiscreteQuantity::assert($item['ordered_qty']);
            $qty = (float) $item['ordered_qty'];
            $cost = (float) $item['unit_cost'];
            if (round($qty * $cost, 4) > 99999999.9999) {
                throw new BusinessException('قيمة بند أمر الشراء تتجاوز السعة المسموحة.');
            }
            $po->items()->create([
                'product_variant_id' => (int) $item['product_variant_id'],
                'ordered_qty' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                'line_total' => round($qty * $cost, 4),
            ]);
        }
    }

    /**
     * Compute header totals from line items including net line totals, taxes, discount, and grand total.
     */
    private function recalculateTotals(PurchaseOrder $po): void
    {
        $items = $po->items()->get();
        $subtotal = round((float) $items->sum(fn (PurchaseOrderItem $i): float => (float) $i->line_total), 2);
        $tax = round((float) $items->sum(fn (PurchaseOrderItem $i): float => (float) $i->line_total * (float) $i->tax_rate / 100), 2);
        if ((float) $po->discount_amount > $subtotal) {
            throw new BusinessException('خصم أمر الشراء لا يمكن أن يتجاوز مجموع البنود.');
        }
        $po->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'grand_total' => round($subtotal - (float) $po->discount_amount + $tax, 2),
        ]);
    }

    /**
     * Delete an unfulfilled draft or cancelled purchase order.
     *
     * Orders with deliveries, received stock, or supplier invoices must be preserved for accounting audit trails.
     *
     * @param  PurchaseOrder  $po
     * @return void
     *
     * @throws BusinessException
     */
    public function deletePurchaseOrder(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po): void {
            $po = $this->lockOrder($po);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)
                || $po->deliveries()->exists() || $po->items()->where('received_qty', '>', 0)->exists()
                || $po->invoices()->exists()) {
                throw new BusinessException('لا يمكن حذف أمر شراء له استلام أو توريدات أو فواتير؛ احتفظ بالمستند للتدقيق.');
            }
            if ($po->purchase_request_id !== null) {
                PurchaseRequest::query()->whereKey($po->purchase_request_id)
                    ->where('status', PurchaseRequestStatus::ConvertedToPo)
                    ->update(['status' => PurchaseRequestStatus::Approved]);
            }
            $po->delete();
        });
    }

    /**
     * Mark a partially received order as completed to write off outstanding balances.
     *
     * @param  PurchaseOrder  $po
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function closeShort(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po): PurchaseOrder {
            $po = $this->lockOrder($po);
            if ($po->status !== PurchaseOrderStatus::PartiallyReceived) {
                throw new BusinessException('إغلاق النقص متاح لأمر مستلم جزئيًا فقط.');
            }
            $po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->update(['status' => SupplierDeliveryStatus::Rejected]);
            $po->update(['status' => PurchaseOrderStatus::Completed]);

            return $po->fresh(['items']);
        });
    }

    private function lockOrder(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
    }

    // ================= Receipts & Weighted Average Cost =================

    /**
     * Intake goods for a purchase order (full or partial), update weighted average cost (WAC),
     * and increment warehouse on-hand inventory.
     *
     * When linked to an Advance Shipping Notice (ASN / supplierDeliveryId), received quantities
     * reconcile against ASN lines and mark the delivery as completed when fully received.
     *
     * @param  PurchaseOrder  $po
     * @param  list<array{purchase_order_item_id: int, qty: float|int|string}>  $receivedItems
     * @param  int|null  $locationId
     * @param  int|null  $supplierDeliveryId
     * @param  int|null  $performedBy
     * @param  string|null  $idempotencyKey
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function receivePurchaseOrder(PurchaseOrder $po, array $receivedItems, ?int $locationId = null, ?int $supplierDeliveryId = null, ?int $performedBy = null, ?string $idempotencyKey = null): PurchaseOrder
    {
        $actor = User::query()->findOrFail($performedBy ?? auth()->id());

        return app(OperationIdempotencyService::class)->run('purchase_receipt', $actor, $idempotencyKey,
            ['purchase_order_id' => $po->id, 'items' => $receivedItems, 'location_id' => $locationId, 'supplier_delivery_id' => $supplierDeliveryId],
            fn () => $this->executePurchaseReceipt($po, $receivedItems, $locationId, $supplierDeliveryId, $actor->id));
    }

    private function executePurchaseReceipt(PurchaseOrder $po, array $receivedItems, ?int $locationId, ?int $supplierDeliveryId, int $performedBy): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $receivedItems, $locationId, $supplierDeliveryId, $performedBy): PurchaseOrder {
            $po = $this->lockOrder($po);
            if (! in_array($po->status, [
                PurchaseOrderStatus::SentToSupplier,
                PurchaseOrderStatus::SupplierAccepted,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new BusinessException("لا يمكن استلام بضاعة لأمر شراء في حالة ({$po->status->value}).");
            }
            // Re-verify branch status at intake time to prevent crediting stock to an inactive branch
            if (! Branch::query()->whereKey($po->branch_id)->where('is_active', true)->exists()) {
                throw new BusinessException('فرع استلام أمر الشراء غير نشط.');
            }

            if ($supplierDeliveryId === null && $po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->exists()) {
                throw new BusinessException('حدد إشعار التوريد المعلق عند الاستلام لمنع احتساب كمياته مرتين.');
            }
            $poItems = $po->items()->orderBy('product_variant_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            usort($receivedItems, fn (array $a, array $b): int => [($poItems->get((int) $a['purchase_order_item_id'])?->product_variant_id ?? 0), (int) $a['purchase_order_item_id']]
                <=> [($poItems->get((int) $b['purchase_order_item_id'])?->product_variant_id ?? 0), (int) $b['purchase_order_item_id']]);
            $variants = ProductVariant::query()->whereIn('id', $poItems->pluck('product_variant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $costFactor = bccomp((string) $po->subtotal, '0', 2) > 0
                ? bcsub('1', bcdiv((string) $po->discount_amount, (string) $po->subtotal, 12), 12) : '1';
            if (bccomp($costFactor, '0', 12) < 0) {
                $costFactor = '0';
            }
            $delivery = null;
            $deliveryItems = collect();
            if ($supplierDeliveryId !== null) {
                $delivery = SupplierDelivery::query()->lockForUpdate()
                    ->where('purchase_order_id', $po->id)
                    ->findOrFail($supplierDeliveryId);
                if ($delivery->status !== SupplierDeliveryStatus::PendingReceipt) {
                    throw new BusinessException('إشعار التوريد هذا تمت معالجته من قبل.');
                }
                $deliveryItems = $delivery->items()->get()->keyBy('purchase_order_item_id');
            }

            foreach ($receivedItems as $itemData) {
                $poItem = $poItems->get((int) $itemData['purchase_order_item_id']);
                if ($poItem === null) {
                    throw new BusinessException('أحد بنود الاستلام لا يتبع أمر الشراء هذا.');
                }
                DiscreteQuantity::assert($itemData['qty']);
                $qtyToReceive = (float) $itemData['qty'];
                if ($qtyToReceive <= 0) {
                    continue;
                }

                $remainingQty = (float) $poItem->ordered_qty - (float) $poItem->received_qty;
                if (round($qtyToReceive, 4) > round($remainingQty, 4)) {
                    throw new BusinessException("الكمية المستلمة ({$qtyToReceive}) أكبر من الكمية المتبقية في أمر الشراء ({$remainingQty}).");
                }

                $deliveryItem = null;
                if ($delivery !== null) {
                    $deliveryItem = $deliveryItems->get($poItem->id);
                    if ($deliveryItem === null) {
                        throw new BusinessException('أحد بنود الاستلام غير موجود في إشعار التوريد المحدد.');
                    }
                    $shippedRemaining = (float) $deliveryItem->shipped_qty - (float) $deliveryItem->received_qty;
                    if (round($qtyToReceive, 4) > round($shippedRemaining, 4)) {
                        throw new BusinessException('الكمية المستلمة أكبر من المشحونة المتبقية في إشعار التوريد.');
                    }
                }

                // 1. Calculate and update new company-wide weighted average cost (WAC)
                $variant = $variants->get($poItem->product_variant_id);
                if ($variant === null) {
                    throw new BusinessException('متغير الصنف غير متاح للاستلام.');
                }

                // Owned inventory includes damaged and in-transit units in every branch.
                $currentTotalOnHand = (string) StockLevel::query()
                    ->where('product_variant_id', $variant->id)
                    ->sum(DB::raw('on_hand + in_transit'));
                $oldCost = (string) $variant->cost_price;
                $newUnitCost = bcadd(bcmul((string) $poItem->unit_cost, $costFactor, 5), '0.00005', 4);

                $safeCurrentStock = bccomp($currentTotalOnHand, '0', 4) < 0 ? '0' : $currentTotalOnHand;
                $totalCost = bcadd(bcmul($safeCurrentStock, $oldCost, 8), bcmul((string) $qtyToReceive, $newUnitCost, 8), 8);
                $newAverageCost = bcadd(bcdiv($totalCost, bcadd($safeCurrentStock, (string) $qtyToReceive, 4), 5), '0.00005', 4);

                $variant->update([
                    'cost_price' => $newAverageCost,
                    'last_cost_price' => $newUnitCost,
                ]);

                // 2. Post physical inventory receipt to warehouse on-hand ledger
                $this->inventory->recordMovement([
                    'product_variant_id' => $variant->id,
                    'branch_id' => (int) $po->branch_id,
                    'location_id' => $locationId,
                    'type' => StockMovementType::PurchaseReceipt,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => $qtyToReceive,
                    'unit_cost' => $newUnitCost,
                    'reference_type' => PurchaseOrder::class,
                    'reference_id' => $po->id,
                    'created_by' => $performedBy ?? auth()->id(),
                    'notes' => "استلام من أمر شراء رقم {$po->po_number}",
                ]);

                // 3. Increment received quantities on PO line and linked ASN item
                $poItem->increment('received_qty', $qtyToReceive);
                $deliveryItem?->increment('received_qty', $qtyToReceive);
            }

            if ($delivery !== null) {
                $allDeliveryReceived = $delivery->items()->get()
                    ->every(fn (SupplierDeliveryItem $i): bool => (float) $i->received_qty >= (float) $i->shipped_qty);
                if ($allDeliveryReceived) {
                    $delivery->update(['status' => SupplierDeliveryStatus::Received]);
                }
            }

            // 4. Update overall PO status based on whether all ordered quantities are satisfied
            $po->load('items');
            $allCompleted = $po->items->every(fn (PurchaseOrderItem $i): bool => (float) $i->received_qty >= (float) $i->ordered_qty);
            $po->update(['status' => $allCompleted ? PurchaseOrderStatus::Completed : PurchaseOrderStatus::PartiallyReceived]);

            return $po->fresh(['items.variant']);
        });
    }

    // ================= Supplier Portal =================

    /**
     * Accept a purchase order via the vendor portal.
     *
     * @param  PurchaseOrder  $po
     * @param  SupplierUser  $user
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function supplierAccept(PurchaseOrder $po, SupplierUser $user): PurchaseOrder
    {
        $this->assertOwnsOrder($po, $user);

        return DB::transaction(function () use ($po, $user): PurchaseOrder {
            $po = $this->lockOrder($po);
            $this->assertOwnsOrder($po, $user);
            if ($po->status !== PurchaseOrderStatus::SentToSupplier) {
                throw new BusinessException("لا يمكن قبول أمر شراء في حالة ({$po->status->value}).");
            }
            $po->update(['status' => PurchaseOrderStatus::SupplierAccepted]);

            return $po->fresh(['items']);
        });
    }

    /**
     * Request price, quantity, or delivery date modifications from the vendor portal.
     *
     * @param  PurchaseOrder  $po
     * @param  SupplierUser  $user
     * @param  string  $notes
     * @return PurchaseOrder
     *
     * @throws BusinessException
     */
    public function supplierRequestChange(PurchaseOrder $po, SupplierUser $user, string $notes): PurchaseOrder
    {
        $this->assertOwnsOrder($po, $user);

        return DB::transaction(function () use ($po, $user, $notes): PurchaseOrder {
            $po = $this->lockOrder($po);
            $this->assertOwnsOrder($po, $user);
            if (! in_array($po->status, [PurchaseOrderStatus::SentToSupplier, PurchaseOrderStatus::SupplierAccepted], true)) {
                throw new BusinessException("لا يمكن طلب تعديل على أمر شراء في حالة ({$po->status->value}).");
            }
            if ($po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->exists()) {
                throw new BusinessException('لا يمكن طلب تعديل وأمر الشراء له توريد بانتظار الاستلام.');
            }
            $po->update([
                'status' => PurchaseOrderStatus::ChangeRequested,
                'supplier_change_notes' => $notes,
            ]);

            return $po->fresh(['items']);
        });
    }

    /**
     * Create an Advance Shipping Notice (ASN) from the supplier portal with attached invoice.
     *
     * Enforces cumulative dispatched quantity caps (already received + pending in ASNs + current).
     *
     * @param  PurchaseOrder  $po
     * @param  SupplierUser  $user
     * @param  array{delivery_date: string, supplier_invoice_no?: string|null, notes?: string|null, items: list<array{purchase_order_item_id: int, shipped_qty: float|int|string}>}  $data
     * @param  UploadedFile|null  $invoiceFile
     * @param  string|null  $idempotencyKey
     * @return SupplierDelivery
     *
     * @throws BusinessException
     */
    public function createSupplierDelivery(PurchaseOrder $po, SupplierUser $user, array $data, ?UploadedFile $invoiceFile = null, ?string $idempotencyKey = null): SupplierDelivery
    {
        $fresh = $po->fresh();
        if ($fresh === null) {
            throw new BusinessException('أمر الشراء غير موجود.', [], 404);
        }
        $this->assertOwnsOrder($fresh, $user);

        return PrivateAttachments::transaction(fn (PrivateAttachments $files) => app(OperationIdempotencyService::class)->run('supplier_delivery', $user, $idempotencyKey,
            ['purchase_order_id' => $po->id, ...$data, 'invoice_hash' => $invoiceFile ? hash_file('sha256', $invoiceFile->getRealPath()) : null],
            fn () => $this->executeSupplierDelivery($po, $user, $data, $invoiceFile, $files)), [[$invoiceFile, 'supplier_deliveries/pending']]);
    }

    private function executeSupplierDelivery(PurchaseOrder $po, SupplierUser $user, array $data, ?UploadedFile $invoiceFile, PrivateAttachments $files): SupplierDelivery
    {
        $this->assertOwnsOrder($po, $user);

        $delivery = DB::transaction(function () use ($po, $user, $data, $invoiceFile, $files): SupplierDelivery {
            $po = $this->lockOrder($po);
            $this->assertOwnsOrder($po, $user);
            if (! in_array($po->status, [
                PurchaseOrderStatus::SentToSupplier,
                PurchaseOrderStatus::SupplierAccepted,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new BusinessException("لا يمكن إرسال توريد لأمر شراء في حالة ({$po->status->value}).");
            }

            // Aggregate duplicate line submissions before evaluating cumulative quantity caps
            $lines = [];
            foreach ($data['items'] as $line) {
                DiscreteQuantity::assert($line['shipped_qty']);
                $itemId = (int) $line['purchase_order_item_id'];
                $lines[$itemId] = ($lines[$itemId] ?? 0.0) + (float) $line['shipped_qty'];
            }

            $poItems = $po->items()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            if ($poItems->count() !== count($lines)) {
                throw new BusinessException('أحد بنود التوريد لا يتبع أمر الشراء هذا.');
            }
            foreach ($lines as $itemId => $shippedQty) {
                $poItem = $poItems->get($itemId);
                $pendingShipment = (float) SupplierDeliveryItem::query()
                    ->where('purchase_order_item_id', $itemId)
                    ->whereHas('delivery', fn ($q) => $q->where('status', SupplierDeliveryStatus::PendingReceipt->value))
                    ->selectRaw('COALESCE(SUM(shipped_qty - received_qty), 0) as total')->value('total');
                if (round((float) $poItem->received_qty + $pendingShipment + $shippedQty, 4) > round((float) $poItem->ordered_qty, 4)) {
                    throw new BusinessException("إجمالي المشحون للصنف ({$poItem->variant?->sku}) يتجاوز الكمية المطلوبة في أمر الشراء.");
                }
            }

            $delivery = SupplierDelivery::create([
                'delivery_number' => $this->settings->generateNextNumber('delivery'),
                'purchase_order_id' => $po->id,
                'supplier_id' => (int) $user->supplier_id,
                'status' => SupplierDeliveryStatus::PendingReceipt,
                'delivery_date' => $data['delivery_date'],
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($lines as $itemId => $shippedQty) {
                $delivery->items()->create([
                    'purchase_order_item_id' => $itemId,
                    'product_variant_id' => $poItems->get($itemId)->product_variant_id,
                    'shipped_qty' => $shippedQty,
                ]);
            }

            // Dispatching items without explicit prior acceptance constitutes implicit acceptance
            if ($po->status === PurchaseOrderStatus::SentToSupplier) {
                $po->update(['status' => PurchaseOrderStatus::SupplierAccepted]);
            }

            if ($invoiceFile !== null) {
                $delivery->update(['invoice_file_path' => $files->store($invoiceFile, "supplier_deliveries/{$delivery->id}")]);
            }

            return $delivery;
        });

        return $delivery->fresh(['items']);
    }

    /**
     * Ensure the authenticated supplier user belongs to the purchase order vendor.
     */
    private function assertOwnsOrder(PurchaseOrder $po, SupplierUser $user): void
    {
        if ((int) $po->supplier_id !== (int) $user->supplier_id) {
            throw new BusinessException('أمر الشراء غير موجود.', [], 404);
        }
    }
}
