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
 * خدمة المشتريات — دورة الشراء كاملة:
 * طلب شراء داخلي (فرع) ← اعتماد ← أمر شراء لمورد ← إرسال ← قبول/طلب تعديل
 * من بوابة المورد ← إشعارات توريد (ASN) ← استلام مخزني.
 *
 * قلب المرحلة المحاسبي: الاستلام يحدّث متوسط التكلفة المرجح (WAC) للمتغير
 * قبل تغذية المخزون، والحركات كلها عبر InventoryService::recordMovement:
 *   NewAvg = (CurrentTotalOnHand × OldCost + ReceivedQty × PurchaseCost)
 *            ÷ (CurrentTotalOnHand + ReceivedQty)
 * حيث CurrentTotalOnHand = مجموع on_hand للمتغير في كل الفروع.
 */
class ProcurementService
{
    public function __construct(
        protected InventoryService $inventory,
        protected SettingService $settings,
    ) {}

    // ================= طلبات الشراء الداخلية =================

    /**
     * @param  array{branch_id: int, notes?: string|null, items: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
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
     * @param  array{branch_id?: int, notes?: string|null, items?: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
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

    public function submitPurchaseRequest(PurchaseRequest $pr): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending, 'الإرسال');
    }

    public function approvePurchaseRequest(PurchaseRequest $pr): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Pending, PurchaseRequestStatus::Approved, 'الاعتماد');
    }

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

    /** @param list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}> $items */
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

    // ================= أوامر الشراء =================

    /**
     * إنشاء أمر شراء (مسودة). عند ربطه بطلب داخلي معتمد تُنسخ بنوده
     * افتراضيًا (بسعر تكلفة المتغير الحالية وضريبة صفر قابلة للتعديل)،
     * ويتحول الطلب إلى converted_to_po.
     *
     * @param  array{supplier_id: int, branch_id?: int|null, purchase_request_id?: int|null, expected_delivery_date?: string|null, discount_amount?: float|int|string|null, items?: list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>}  $data
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
     * @param  array{supplier_id?: int, branch_id?: int, expected_delivery_date?: string|null, discount_amount?: float|int|string|null, items?: list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>}  $data
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

    /** ختم الاعتماد الداخلي (approved_by) على مسودة — الإرسال خطوة مستقلة. */
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

    /** @param list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}> $items */
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

    /** إجماليات الرأس من البنود: subtotal وضرائب البنود والخصم والنهائي. */
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

    // ================= الاستلام ومتوسط التكلفة =================

    /**
     * استلام بضاعة أمر شراء (كلي أو جزئي) + تحديث متوسط التكلفة المرجح
     * + تغذية المخزون — قلب المرحلة 10.
     *
     * عند ربط الاستلام بإشعار توريد (supplierDeliveryId) تُطابَق الكميات
     * مع بنوده وتُحدَّث حالته إلى received عند اكتمال استلام مشحوناته.
     *
     * @param  list<array{purchase_order_item_id: int, qty: float|int|string}>  $receivedItems
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
            // إعادة فحص نشاط فرع الاستلام لحظة الاستلام الفعلي (L4): قد يكون
            // الفرع عُطّل بعد إرسال الأمر، فلا يصح تغذية مخزون فرع ميت.
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
            $costFactor = (float) $po->subtotal > 0 ? max(0.0, 1 - (float) $po->discount_amount / (float) $po->subtotal) : 1.0;
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

                // 1. المتغير مقفولًا لتحديث متوسط التكلفة بأمان
                $variant = $variants->get($poItem->product_variant_id);
                if ($variant === null) {
                    throw new BusinessException('متغير الصنف غير متاح للاستلام.');
                }

                // Includes damaged stock: it remains part of physical on_hand across all branches.
                $currentTotalOnHand = (float) StockLevel::query()
                    ->where('product_variant_id', $variant->id)
                    ->sum('on_hand');
                $oldCost = (float) $variant->cost_price;
                $newUnitCost = round((float) $poItem->unit_cost * $costFactor, 4);

                // معادلة المتوسط المرجح للتكلفة (WAC)
                $safeCurrentStock = max(0.0, $currentTotalOnHand);
                $newAverageCost = ($safeCurrentStock + $qtyToReceive) > 0
                    ? (($safeCurrentStock * $oldCost) + ($qtyToReceive * $newUnitCost)) / ($safeCurrentStock + $qtyToReceive)
                    : $newUnitCost;

                $variant->update([
                    'cost_price' => round($newAverageCost, 4),
                    'last_cost_price' => $newUnitCost,
                ]);

                // 2. حركة إضافة للمخزون عبر InventoryService حصرًا
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

                // 3. تحديث المستلم في بند الأمر (وبند إشعار التوريد عند الربط)
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

            // 4. حالة الأمر: مكتمل عند استلام كل بنوده، وإلا جزئي
            $po->load('items');
            $allCompleted = $po->items->every(fn (PurchaseOrderItem $i): bool => (float) $i->received_qty >= (float) $i->ordered_qty);
            $po->update(['status' => $allCompleted ? PurchaseOrderStatus::Completed : PurchaseOrderStatus::PartiallyReceived]);

            return $po->fresh(['items.variant']);
        });
    }

    // ================= بوابة المورد =================

    /** قبول المورد لأمر شراء مُرسل إليه (نطاق المورد يُفرض هنا أيضًا). */
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

    /** طلب المورد تعديل الأسعار/الكميات/الموعد مع ملاحظاته للإدارة. */
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
     * إشعار توريد (ASN) من المورد: كميات مشحونة فعليًا في هذه الدفعة +
     * فاتورة مبدئية مرفوعة. سقف الشحن التراكمي لكل بند = الكمية المطلوبة.
     *
     * @param  array{delivery_date: string, supplier_invoice_no?: string|null, notes?: string|null, items: list<array{purchase_order_item_id: int, shipped_qty: float|int|string}>}  $data
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
            fn () => $this->executeSupplierDelivery($po, $user, $data, $invoiceFile, $files)));
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

            // تجميع البنود المكررة قبل فحص سقف الشحن التراكمي
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

            // الشحن دون قبول صريح = قبول ضمني بالالتزام
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

    /** حارس نطاق بوابة المورد: أمر شراء مورد آخر غير موجود أصلًا في عالم هذا المستخدم. */
    private function assertOwnsOrder(PurchaseOrder $po, SupplierUser $user): void
    {
        if ((int) $po->supplier_id !== (int) $user->supplier_id) {
            throw new BusinessException('أمر الشراء غير موجود.', [], 404);
        }
    }
}
