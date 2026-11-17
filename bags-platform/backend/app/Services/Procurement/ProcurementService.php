<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\PurchaseRequestStatus;
use App\Enums\Procurement\SupplierDeliveryStatus;
use App\Enums\Procurement\SupplierInvoiceStatus;
use App\Events\Procurement\PurchaseOrderSentToSupplier;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
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
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\InventoryService;
use App\Services\Shared\DiscreteQuantity;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\PrivateAttachments;
use App\Services\Shared\SettingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
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
        protected BranchAccess $branchAccess,
        protected SupplierFinanceService $finance,
    ) {}

    /**
     * Resolve the acting staff user and fail closed when no user context exists.
     */
    private function resolveActor(?User $actor): User
    {
        $actor ??= auth()->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('هذه العملية تتطلب مستخدمًا موظفًا معتمدًا.');
        }

        return $actor;
    }

    /**
     * Enforce branch isolation for the acting user before any procurement mutation.
     */
    private function assertBranchAccess(?User $actor, int ...$branchIds): void
    {
        $this->branchAccess->assertBranch($this->resolveActor($actor)->id, ...$branchIds);
    }

    // ================= Internal Purchase Requests =================

    /**
     * Create a draft internal purchase request for a branch.
     *
     * @param  array{branch_id: int, notes?: string|null, items: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
     */
    public function createPurchaseRequest(array $data, User $requester): PurchaseRequest
    {
        $this->assertBranchAccess($requester, (int) $data['branch_id']);

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
     * @param  array{branch_id?: int, notes?: string|null, items?: list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>}  $data
     *
     * @throws BusinessException
     */
    public function updatePurchaseRequest(PurchaseRequest $pr, array $data, ?User $actor = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $data, $actor): PurchaseRequest {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            $this->assertBranchAccess($actor, (int) $pr->branch_id);
            if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $pr->branch_id) {
                $this->assertBranchAccess($actor, (int) $data['branch_id']);
            }
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
     */
    public function submitPurchaseRequest(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending, 'الإرسال', $actor);
    }

    /**
     * Approve a pending purchase request, making it eligible for PO conversion.
     */
    public function approvePurchaseRequest(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? $this->resolveActor(null);
        if (! (bool) $this->settings->get('allow_self_approve_purchases', false)
            && (int) $pr->requested_by === (int) $actor->id) {
            throw new BusinessException('لا يجوز لمن أنشأ طلب الشراء اعتماده بنفسه.');
        }

        return $this->transitionRequest($pr, PurchaseRequestStatus::Pending, PurchaseRequestStatus::Approved, 'الاعتماد', $actor);
    }

    /**
     * Reject a pending purchase request.
     */
    public function rejectPurchaseRequest(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        return $this->transitionRequest($pr, PurchaseRequestStatus::Pending, PurchaseRequestStatus::Rejected, 'الرفض', $actor);
    }

    private function transitionRequest(PurchaseRequest $pr, PurchaseRequestStatus $from, PurchaseRequestStatus $to, string $action, ?User $actor = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $from, $to, $action, $actor): PurchaseRequest {
            $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            $this->assertBranchAccess($actor, (int) $pr->branch_id);
            if ($pr->status !== $from) {
                throw new BusinessException("لا يمكن {$action} طلب شراء في حالة ({$pr->status->value}).");
            }
            $pr->update(['status' => $to]);

            return $pr->fresh(['items']);
        });
    }

    /**
     * @param  list<array{product_variant_id: int, requested_qty: float|int|string, notes?: string|null}>  $items
     */
    private function syncRequestItems(PurchaseRequest $pr, array $items): void
    {
        $this->assertActiveVariants(array_column($items, 'product_variant_id'));
        foreach ($items as $item) {
            DiscreteQuantity::assert($item['requested_qty']);
            $pr->items()->create([
                'product_variant_id' => (int) $item['product_variant_id'],
                'requested_qty' => (string) BigDecimal::of((string) $item['requested_qty'])->toScale(4, RoundingMode::HalfUp),
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
                $this->assertBranchAccess($creator, (int) $pr->branch_id);
                if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $pr->branch_id) {
                    throw new BusinessException('فرع أمر الشراء يجب أن يطابق فرع طلب الشراء الداخلي المرتبط.');
                }
                $pr->load('items.variant');
            }

            $items = $data['items'] ?? [];
            if ($items !== [] && $pr !== null) {
                $prVariantIds = $pr->items->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();
                foreach ($items as $item) {
                    if (! in_array((int) $item['product_variant_id'], $prVariantIds, true)) {
                        throw new BusinessException('بنود أمر الشراء يجب أن تطابق أصناف طلب الشراء الداخلي المرتبط.');
                    }
                }
            }
            if ($items === [] && $pr !== null) {
                $items = $pr->items->map(fn ($item): array => [
                    'product_variant_id' => (int) $item->product_variant_id,
                    'ordered_qty' => (string) BigDecimal::of((string) $item->requested_qty)->toScale(4, RoundingMode::HalfUp),
                    'unit_cost' => (string) BigDecimal::of((string) ($item->variant?->cost_price ?? 0))->toScale(4, RoundingMode::HalfUp),
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

            $this->assertBranchAccess($creator, $branchId);
            $this->assertActiveSupplier((int) $data['supplier_id'], true);
            $this->assertActiveBranch($branchId, 'فرع الاستلام غير نشط.', true);
            $po = PurchaseOrder::create([
                'po_number' => $this->settings->generateNextNumber('purchase_order'),
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => $branchId,
                'purchase_request_id' => $pr?->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'discount_amount' => (string) BigDecimal::of((string) ($data['discount_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp),
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
     * @param  array{supplier_id?: int, branch_id?: int, expected_delivery_date?: string|null, discount_amount?: float|int|string|null, items?: list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>}  $data
     *
     * @throws BusinessException
     */
    public function updatePurchaseOrder(PurchaseOrder $po, array $data, User $editor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $editor): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($editor, (int) $po->branch_id);
            if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $po->branch_id) {
                $this->assertBranchAccess($editor, (int) $data['branch_id']);
            }
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException('لا يمكن تعديل أمر شراء إلا وهو مسودة أو بانتظار معالجة تعديلات المورد.');
            }
            // An empty payload must not overwrite last_modified_by and launder the
            // separation-of-duties check in approvePurchaseOrder().
            if (array_intersect([
                'supplier_id', 'branch_id', 'items', 'discount_amount', 'expected_delivery_date',
            ], array_keys($data)) === []) {
                throw new BusinessException('لا يوجد تغيير صالح في أمر الشراء.');
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
            if (array_intersect(['supplier_id', 'branch_id', 'items', 'discount_amount'], array_keys($data)) !== []
                && $po->invoices()->where('status', '!=', SupplierInvoiceStatus::Cancelled)->exists()) {
                throw new BusinessException('لا يمكن تعديل شروط أمر شراء مرتبط بفاتورة غير ملغاة.');
            }
            // Modifying core commercial terms invalidates prior internal approval
            if (array_intersect(['supplier_id', 'branch_id', 'items', 'discount_amount', 'expected_delivery_date'], array_keys($data))) {
                $data['approved_by'] = null;
            }
            if (isset($data['supplier_id'])) {
                $this->assertActiveSupplier((int) $data['supplier_id'], true);
            }
            if (isset($data['branch_id'])) {
                $this->assertActiveBranch((int) $data['branch_id'], 'فرع الاستلام غير نشط.', true);
            }
            $attributes = array_intersect_key($data, array_flip([
                'supplier_id', 'branch_id', 'expected_delivery_date', 'discount_amount', 'approved_by',
            ]));
            if (array_key_exists('discount_amount', $attributes)) {
                $attributes['discount_amount'] = (string) BigDecimal::of((string) $attributes['discount_amount'])->toScale(2, RoundingMode::HalfUp);
            }
            $probe = clone $po;
            $probe->fill($attributes);

            $itemsChanged = false;
            if (isset($data['items'])) {
                $existingItems = $po->items()->orderBy('product_variant_id')->get();
                $newItems = collect($data['items'])->sortBy(fn ($it) => (int) $it['product_variant_id'])->values();
                if ($existingItems->count() !== $newItems->count()) {
                    $itemsChanged = true;
                } else {
                    foreach ($newItems as $idx => $newItem) {
                        $existing = $existingItems[$idx];
                        if ((int) $existing->product_variant_id !== (int) $newItem['product_variant_id']
                            || BigDecimal::of((string) $newItem['ordered_qty'])->compareTo((string) $existing->ordered_qty) !== 0
                            || BigDecimal::of((string) $newItem['unit_cost'])->compareTo((string) $existing->unit_cost) !== 0
                            || BigDecimal::of((string) ($newItem['tax_rate'] ?? 0))->compareTo((string) $existing->tax_rate) !== 0
                            || (string) ($newItem['notes'] ?? '') !== (string) ($existing->notes ?? '')) {
                            $itemsChanged = true;
                            break;
                        }
                    }
                }
            }

            if (! $probe->isDirty() && ! $itemsChanged) {
                throw new BusinessException('لا يوجد تغيير فعلي في أمر الشراء.');
            }
            $attributes['last_modified_by'] = $editor->id;
            $po->update($attributes);
            if ($itemsChanged) {
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
     *
     * @throws BusinessException
     */
    public function approvePurchaseOrder(PurchaseOrder $po, User $admin): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $admin): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($admin, (int) $po->branch_id);
            $this->assertActiveSupplier((int) $po->supplier_id, true);
            $this->assertActiveBranch((int) $po->branch_id, 'فرع أمر الشراء غير نشط.', true);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException('لا يمكن اعتماد أمر شراء إلا وهو مسودة أو بانتظار معالجة التعديلات.');
            }
            $lastModifiedBy = $po->last_modified_by !== null ? (int) $po->last_modified_by : null;
            if (! (bool) $this->settings->get('allow_self_approve_purchases', false)
                && ((int) $po->created_by === (int) $admin->id
                    || ($lastModifiedBy !== null && $lastModifiedBy === (int) $admin->id))) {
                throw new BusinessException('لا يجوز لمن أنشأ أمر الشراء أو آخر من عدّله اعتماده بنفسه.');
            }
            $po->update(['approved_by' => $admin->id]);

            return $po->fresh(['items']);
        });
    }

    /**
     * Dispatch an approved purchase order to the vendor and fire notification event.
     *
     *
     * @throws BusinessException
     */
    public function sendPurchaseOrder(PurchaseOrder $po, ?User $actor = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($actor, (int) $po->branch_id);
            $this->assertActiveBranch((int) $po->branch_id, 'فرع أمر الشراء غير نشط.', true);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::ChangeRequested], true)) {
                throw new BusinessException("لا يمكن إرسال أمر شراء في حالة ({$po->status->value}).");
            }
            if ($po->approved_by === null) {
                throw new BusinessException('يجب اعتماد أمر الشراء قبل إرساله إلى المورد.');
            }
            if ($po->items()->count() === 0) {
                throw new BusinessException('لا يمكن إرسال أمر شراء بلا بنود.');
            }
            $this->assertActiveSupplier((int) $po->supplier_id, true);
            $po->update(['status' => PurchaseOrderStatus::SentToSupplier, 'supplier_change_notes' => null]);

            $sent = $po->fresh(['items']);
            PurchaseOrderSentToSupplier::dispatch($sent);

            return $sent;
        });
    }

    /**
     * Cancel an active purchase order and reject any pending vendor delivery notices.
     *
     *
     * @throws BusinessException
     */
    public function cancelPurchaseOrder(PurchaseOrder $po, ?User $actor = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($actor, (int) $po->branch_id);
            if ($po->items()->where('received_qty', '>', 0)->exists()) {
                throw new BusinessException('لا يمكن إلغاء أمر شراء بدأ استلام بضاعته.');
            }
            if ($po->invoices()->where('status', '!=', SupplierInvoiceStatus::Cancelled)->exists()) {
                throw new BusinessException('لا يمكن إلغاء أمر شراء عليه فواتير غير ملغاة.');
            }
            if (! in_array($po->status, [
                PurchaseOrderStatus::Draft, PurchaseOrderStatus::SentToSupplier,
                PurchaseOrderStatus::SupplierAccepted, PurchaseOrderStatus::ChangeRequested,
            ], true)) {
                throw new BusinessException("لا يمكن إلغاء أمر شراء في حالة ({$po->status->value}).");
            }
            $po->update(['status' => PurchaseOrderStatus::Cancelled]);
            $this->rejectOrClosePendingDeliveries($po);
            $this->releasePurchaseRequestIfUnused($po);

            return $po->fresh(['items']);
        });
    }

    /**
     * @param  list<array{product_variant_id: int, ordered_qty: float|int|string, unit_cost: float|int|string, tax_rate?: float|int|string|null}>  $items
     */
    private function syncOrderItems(PurchaseOrder $po, array $items): void
    {
        $this->assertActiveVariants(array_column($items, 'product_variant_id'));
        foreach ($items as $item) {
            DiscreteQuantity::assert($item['ordered_qty']);
            $qty = BigDecimal::of((string) $item['ordered_qty'])->toScale(4);
            $cost = BigDecimal::of((string) $item['unit_cost'])->toScale(4, RoundingMode::HalfUp);
            $lineTotal = $qty->multipliedBy($cost)->toScale(4, RoundingMode::HalfUp);
            if ($lineTotal->compareTo('9999999999999999.9999') > 0) {
                throw new BusinessException('قيمة بند أمر الشراء تتجاوز السعة المسموحة.');
            }
            $po->items()->create([
                'product_variant_id' => (int) $item['product_variant_id'],
                'ordered_qty' => (string) $qty,
                'unit_cost' => (string) $cost,
                'tax_rate' => (string) BigDecimal::of((string) ($item['tax_rate'] ?? 0))
                    ->toScale(2, RoundingMode::HalfUp),
                'line_total' => (string) $lineTotal,
            ]);
        }
    }

    /**
     * Compute header totals from line items including net line totals, taxes, discount, and grand total.
     */
    private function recalculateTotals(PurchaseOrder $po): void
    {
        $items = $po->items()->get();
        $subtotalExact = BigDecimal::zero();
        foreach ($items as $item) {
            $subtotalExact = $subtotalExact->plus((string) $item->line_total);
        }
        $subtotal = $subtotalExact->toScale(2, RoundingMode::HalfUp);
        $discount = BigDecimal::of((string) $po->discount_amount)->toScale(2, RoundingMode::HalfUp);
        if ($discount->compareTo($subtotal) > 0) {
            throw new BusinessException('خصم أمر الشراء لا يمكن أن يتجاوز مجموع البنود.');
        }
        // A commercial discount reduces the VAT taxable base proportionally per line.
        $taxableFactor = $subtotal->compareTo('0') > 0
            ? $subtotal->minus($discount)->dividedBy($subtotal, 12, RoundingMode::HalfUp)
            : BigDecimal::zero();
        $taxExact = BigDecimal::zero();
        foreach ($items as $item) {
            $taxExact = $taxExact->plus(
                BigDecimal::of((string) $item->line_total)
                    ->multipliedBy($taxableFactor)
                    ->multipliedBy((string) $item->tax_rate)
                    ->dividedBy('100', 12, RoundingMode::HalfUp)
            );
        }
        $tax = $taxExact->toScale(2, RoundingMode::HalfUp);
        $po->update([
            'subtotal' => (string) $subtotal,
            'tax_amount' => (string) $tax,
            'grand_total' => (string) $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HalfUp),
        ]);
    }

    /**
     * Delete an unfulfilled draft or cancelled purchase order.
     *
     * Orders with deliveries, received stock, or supplier invoices must be preserved for accounting audit trails.
     *
     *
     * @throws BusinessException
     */
    public function deletePurchaseOrder(PurchaseOrder $po, ?User $actor = null): void
    {
        DB::transaction(function () use ($po, $actor): void {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($actor, (int) $po->branch_id);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)
                || $po->deliveries()->exists() || $po->items()->where('received_qty', '>', 0)->exists()
                || $po->invoices()->exists()) {
                throw new BusinessException('لا يمكن حذف أمر شراء له استلام أو توريدات أو فواتير؛ احتفظ بالمستند للتدقيق.');
            }
            $this->releasePurchaseRequestIfUnused($po);
            $po->delete();
        });
    }

    /**
     * Mark a partially received order as completed to write off outstanding balances.
     *
     *
     * @throws BusinessException
     */
    public function closeShort(PurchaseOrder $po, ?User $actor = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($actor, (int) $po->branch_id);
            if ($po->status !== PurchaseOrderStatus::PartiallyReceived) {
                throw new BusinessException('إغلاق النقص متاح لأمر مستلم جزئيًا فقط.');
            }
            $this->rejectOrClosePendingDeliveries($po);
            $po->update(['status' => PurchaseOrderStatus::Completed]);
            // Closing short shrinks the invoice ceiling to the received value; refuse
            // to complete while already-posted invoices exceed that value.
            $this->finance->assertInvoicesFitClosedOrder($po);

            return $po->fresh(['items']);
        });
    }

    /**
     * Close one pending delivery notice without closing the whole purchase order.
     */
    public function closeDeliveryShort(SupplierDelivery $delivery, ?User $actor = null): SupplierDelivery
    {
        return DB::transaction(function () use ($delivery, $actor): SupplierDelivery {
            // Canonical lock hierarchy: Supplier -> PurchaseOrder -> Deliveries.
            $order = PurchaseOrder::query()->findOrFail($delivery->purchase_order_id);
            $order = $this->lockSupplierThenOrder($order);
            $this->assertBranchAccess($actor, (int) $order->branch_id);
            $delivery = $order->deliveries()->lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->status !== SupplierDeliveryStatus::PendingReceipt) {
                throw new BusinessException('لا يمكن إغلاق إشعار توريد إلا وهو بانتظار الاستلام.');
            }
            $delivery->update(['status' => $this->deliveryCloseStatus($delivery)]);

            return $delivery->fresh(['items']);
        });
    }

    /**
     * Delete a draft purchase request under a row lock so a concurrent approval cannot lose the row.
     */
    public function deletePurchaseRequest(PurchaseRequest $purchaseRequest, ?User $actor = null): void
    {
        DB::transaction(function () use ($purchaseRequest, $actor): void {
            $purchaseRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->assertBranchAccess($actor, (int) $purchaseRequest->branch_id);
            if ($purchaseRequest->status !== PurchaseRequestStatus::Draft) {
                throw new BusinessException('لا يمكن حذف طلب شراء إلا وهو مسودة.');
            }
            $purchaseRequest->delete();
        });
    }

    private function releasePurchaseRequestIfUnused(PurchaseOrder $po): void
    {
        if ($po->purchase_request_id === null) {
            return;
        }
        $stillLive = PurchaseOrder::query()
            ->where('purchase_request_id', $po->purchase_request_id)
            ->where('id', '!=', $po->id)
            ->where('status', '!=', PurchaseOrderStatus::Cancelled)
            ->exists();
        if ($stillLive) {
            return;
        }
        $request = PurchaseRequest::query()->lockForUpdate()->find($po->purchase_request_id);
        if ($request !== null && $request->status === PurchaseRequestStatus::ConvertedToPo) {
            $request->update(['status' => PurchaseRequestStatus::Approved]);
        }
    }

    private function rejectOrClosePendingDeliveries(PurchaseOrder $po): void
    {
        $pending = $po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->lockForUpdate()->get();
        foreach ($pending as $delivery) {
            $delivery->update(['status' => $this->deliveryCloseStatus($delivery)]);
        }
    }

    private function deliveryCloseStatus(SupplierDelivery $delivery): SupplierDeliveryStatus
    {
        $receivedAny = $delivery->items()->where('received_qty', '>', 0)->exists();

        return $receivedAny ? SupplierDeliveryStatus::ClosedShort : SupplierDeliveryStatus::Rejected;
    }

    private function lockOrder(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
    }

    /**
     * Canonical lock hierarchy: Supplier -> PurchaseOrder.
     * Locks the supplier row first so concurrent finance (Supplier -> PO)
     * and procurement (PO -> Supplier) transactions acquire locks in the
     * same order and cannot deadlock. Re-locks if supplier_id changed.
     */
    private function lockSupplierThenOrder(PurchaseOrder $po): PurchaseOrder
    {
        $supplierId = (int) $po->supplier_id;
        Supplier::query()->whereKey($supplierId)->lockForUpdate()->first();
        $locked = $this->lockOrder($po);
        if ((int) $locked->supplier_id !== $supplierId) {
            Supplier::query()->whereKey((int) $locked->supplier_id)->lockForUpdate()->first();
        }

        return $locked;
    }

    // ================= Receipts & Weighted Average Cost =================

    /**
     * Intake goods for a purchase order (full or partial), update weighted average cost (WAC),
     * and increment warehouse on-hand inventory.
     *
     * When linked to an Advance Shipping Notice (ASN / supplierDeliveryId), received quantities
     * reconcile against ASN lines and mark the delivery as completed when fully received.
     *
     * @param  list<array{purchase_order_item_id: int, qty: float|int|string}>  $receivedItems
     *
     * @throws BusinessException
     */
    public function receivePurchaseOrder(PurchaseOrder $po, array $receivedItems, ?int $locationId = null, ?int $supplierDeliveryId = null, ?int $performedBy = null, ?string $idempotencyKey = null): PurchaseOrder
    {
        $actor = $performedBy !== null
            ? User::query()->findOrFail($performedBy)
            : $this->resolveActor(null);

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return app(OperationIdempotencyService::class)->run('purchase_receipt', $actor, $idempotencyKey,
            ['purchase_order_id' => $po->id, 'items' => $receivedItems, 'location_id' => $locationId, 'supplier_delivery_id' => $supplierDeliveryId],
            fn () => $this->executePurchaseReceipt($po, $receivedItems, $locationId, $supplierDeliveryId, $actor));
    }

    private function executePurchaseReceipt(PurchaseOrder $po, array $receivedItems, ?int $locationId, ?int $supplierDeliveryId, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $receivedItems, $locationId, $supplierDeliveryId, $actor): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertBranchAccess($actor, (int) $po->branch_id);
            if (! in_array($po->status, [
                PurchaseOrderStatus::SentToSupplier,
                PurchaseOrderStatus::SupplierAccepted,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new BusinessException("لا يمكن استلام بضاعة لأمر شراء في حالة ({$po->status->value}).");
            }
            // Re-verify branch status at intake time to prevent crediting stock to an inactive branch
            $this->assertActiveBranch((int) $po->branch_id, 'فرع استلام أمر الشراء غير نشط.', true);

            if ($supplierDeliveryId === null && $po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->exists()) {
                throw new BusinessException('حدد إشعار التوريد المعلق عند الاستلام لمنع احتساب كمياته مرتين.');
            }
            if ($locationId !== null) {
                $location = Location::query()->whereKey($locationId)->lockForUpdate()->first();
                if ($location === null || ! $location->is_active) {
                    throw new BusinessException('موقع الاستلام غير نشط أو غير موجود.');
                }
                if ((int) $location->branch_id !== (int) $po->branch_id) {
                    throw new BusinessException('موقع الاستلام لا يتبع فرع أمر الشراء.');
                }
            }
            $poItems = $po->items()->orderBy('product_variant_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // Collapse duplicate lines so one PO item cannot be received twice in
            // the same request with inflated quantities or split stock movements.
            $received = [];
            foreach ($receivedItems as $itemData) {
                DiscreteQuantity::assert($itemData['qty']);
                $lineId = (int) $itemData['purchase_order_item_id'];
                $received[$lineId] = BigDecimal::of((string) ($received[$lineId] ?? '0'))
                    ->plus((string) $itemData['qty'])
                    ->toScale(4, RoundingMode::HalfUp)
                    ->__toString();
            }
            $receivedItems = [];
            foreach ($received as $lineId => $qty) {
                $receivedItems[] = ['purchase_order_item_id' => $lineId, 'qty' => $qty];
            }
            usort($receivedItems, fn (array $a, array $b): int => [($poItems->get((int) $a['purchase_order_item_id'])?->product_variant_id ?? 0), (int) $a['purchase_order_item_id']]
                <=> [($poItems->get((int) $b['purchase_order_item_id'])?->product_variant_id ?? 0), (int) $b['purchase_order_item_id']]);
            $variantIds = [];
            foreach ($receivedItems as $itemData) {
                $line = $poItems->get((int) $itemData['purchase_order_item_id']);
                if ($line !== null && BigDecimal::of((string) $itemData['qty'])->isPositive()) {
                    $variantIds[] = (int) $line->product_variant_id;
                }
            }
            if ($variantIds === []) {
                throw new BusinessException('يجب استلام كمية موجبة من بند واحد على الأقل.');
            }
            $variants = ProductVariant::query()->whereIn('id', array_values(array_unique($variantIds)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // Lock every owned stock row for these variants before reading the WAC denominator.
            StockLevel::query()
                ->whereIn('product_variant_id', $variants->keys())
                ->orderBy('branch_id')->orderByRaw('location_id ASC NULLS FIRST')->orderBy('product_variant_id')
                ->lockForUpdate()->get();
            // Aggregate owned units once per variant while every stock row is locked,
            // instead of re-summing inside the receipt loop.
            $currentTotals = StockLevel::query()
                ->whereIn('product_variant_id', $variants->keys())
                ->groupBy('product_variant_id')
                ->selectRaw('product_variant_id, SUM(on_hand + in_transit) as owned_total')
                ->pluck('owned_total', 'product_variant_id');
            $costFactor = BigDecimal::of((string) $po->subtotal)->isPositive()
                ? BigDecimal::of('1')->minus(
                    BigDecimal::of((string) $po->discount_amount)
                        ->dividedBy((string) $po->subtotal, 12, RoundingMode::HalfUp)
                )
                : BigDecimal::of('1');
            if ($costFactor->isNegative()) {
                $costFactor = BigDecimal::of('0');
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
                $deliveryItems = $delivery->items()->lockForUpdate()->get()->keyBy('purchase_order_item_id');
            }

            $receiptLines = [];
            $variantTotals = [];
            foreach ($receivedItems as $itemData) {
                $poItem = $poItems->get((int) $itemData['purchase_order_item_id']);
                if ($poItem === null) {
                    throw new BusinessException('أحد بنود الاستلام لا يتبع أمر الشراء هذا.');
                }
                DiscreteQuantity::assert($itemData['qty']);
                $qtyToReceive = BigDecimal::of((string) $itemData['qty'])->toScale(4, RoundingMode::HalfUp);
                if (! $qtyToReceive->isPositive()) {
                    continue;
                }

                $remainingQty = BigDecimal::of((string) $poItem->ordered_qty)
                    ->minus((string) $poItem->received_qty)
                    ->toScale(4, RoundingMode::HalfUp);
                if ($qtyToReceive->compareTo($remainingQty) > 0) {
                    throw new BusinessException("الكمية المستلمة ({$qtyToReceive}) أكبر من الكمية المتبقية في أمر الشراء ({$remainingQty}).");
                }

                $deliveryItem = null;
                if ($delivery !== null) {
                    $deliveryItem = $deliveryItems->get($poItem->id);
                    if ($deliveryItem === null) {
                        throw new BusinessException('أحد بنود الاستلام غير موجود في إشعار التوريد المحدد.');
                    }
                    $shippedRemaining = BigDecimal::of((string) $deliveryItem->shipped_qty)
                        ->minus((string) $deliveryItem->received_qty)
                        ->toScale(4, RoundingMode::HalfUp);
                    if ($qtyToReceive->compareTo($shippedRemaining) > 0) {
                        throw new BusinessException('الكمية المستلمة أكبر من المشحونة المتبقية في إشعار التوريد.');
                    }
                }

                $variant = $variants->get($poItem->product_variant_id);
                if ($variant === null) {
                    throw new BusinessException('متغير الصنف غير متاح للاستلام.');
                }

                // 1. Accumulate per-variant receipt value so duplicate variants receive
                // one weighted average update even when they span multiple PO lines.
                $newUnitCost = BigDecimal::of((string) $poItem->unit_cost)
                    ->multipliedBy($costFactor)
                    ->toScale(4, RoundingMode::HalfUp);
                $receiptLines[] = [
                    'poItem' => $poItem,
                    'deliveryItem' => $deliveryItem,
                    'variant' => $variant,
                    'qty' => $qtyToReceive->__toString(),
                    'unitCost' => $newUnitCost->__toString(),
                ];
                $variantTotals[$variant->id] ??= ['qty' => BigDecimal::zero(), 'value' => BigDecimal::zero()];
                $variantTotals[$variant->id]['qty'] = $variantTotals[$variant->id]['qty']
                    ->plus($qtyToReceive)
                    ->toScale(4, RoundingMode::HalfUp);
                $variantTotals[$variant->id]['value'] = $variantTotals[$variant->id]['value']
                    ->plus($qtyToReceive->multipliedBy($newUnitCost))
                    ->toScale(8, RoundingMode::HalfUp);
            }

            // 2. Calculate and update company-wide weighted average cost (WAC) once per variant.
            foreach ($variantTotals as $variantId => $totals) {
                $variant = $variants->get($variantId);
                if ($variant === null) {
                    throw new BusinessException('متغير الصنف غير متاح للاستلام.');
                }
                $currentTotalOnHand = BigDecimal::of((string) ($currentTotals[$variantId] ?? '0'));
                if ($currentTotalOnHand->isNegative()) {
                    $currentTotalOnHand = BigDecimal::zero();
                }
                $weightedUnitCost = $totals['qty']->isPositive()
                    ? $totals['value']->dividedBy($totals['qty'], 8, RoundingMode::HalfUp)
                    : BigDecimal::zero();
                $newAverageCost = InventoryService::calculateWeightedAverageCost(
                    $currentTotalOnHand->__toString(),
                    (string) $variant->cost_price,
                    $totals['qty']->__toString(),
                    $weightedUnitCost->__toString(),
                );
                $variant->update([
                    'cost_price' => $newAverageCost,
                    'last_cost_price' => $weightedUnitCost->toScale(4, RoundingMode::HalfUp)->__toString(),
                ]);
            }

            // 3. Post physical inventory receipts and increment received quantities.
            foreach ($receiptLines as $line) {
                $this->inventory->recordMovement([
                    'product_variant_id' => $line['variant']->id,
                    'branch_id' => (int) $po->branch_id,
                    'location_id' => $locationId,
                    'type' => StockMovementType::PurchaseReceipt,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => $line['qty'],
                    'unit_cost' => $line['unitCost'],
                    'reference_type' => PurchaseOrder::class,
                    'reference_id' => $po->id,
                    'created_by' => $actor->id,
                    'notes' => "استلام من أمر شراء رقم {$po->po_number}",
                ]);
                $line['poItem']->increment('received_qty', $line['qty']);
                $line['deliveryItem']?->increment('received_qty', $line['qty']);
            }

            if ($delivery !== null) {
                $allDeliveryReceived = $delivery->items()->lockForUpdate()->get()
                    ->every(fn (SupplierDeliveryItem $i): bool => BigDecimal::of((string) $i->received_qty)
                        ->compareTo((string) $i->shipped_qty) >= 0);
                if ($allDeliveryReceived) {
                    $delivery->update(['status' => SupplierDeliveryStatus::Received]);
                }
            }

            // 4. Update overall PO status based on whether all ordered quantities are satisfied
            $po->load('items');
            $allCompleted = $po->items->every(fn (PurchaseOrderItem $i): bool => BigDecimal::of((string) $i->received_qty)
                ->compareTo((string) $i->ordered_qty) >= 0);
            $po->update(['status' => $allCompleted ? PurchaseOrderStatus::Completed : PurchaseOrderStatus::PartiallyReceived]);

            return $po->fresh(['items.variant']);
        });
    }

    // ================= Supplier Portal =================

    /**
     * Accept a purchase order via the vendor portal.
     *
     *
     * @throws BusinessException
     */
    public function supplierAccept(PurchaseOrder $po, SupplierUser $user): PurchaseOrder
    {
        $this->assertOwnsOrder($po, $user);

        return DB::transaction(function () use ($po, $user): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertOwnsOrder($po, $user, verifySupplierInDatabase: true);
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
     *
     * @throws BusinessException
     */
    public function supplierRequestChange(PurchaseOrder $po, SupplierUser $user, string $notes): PurchaseOrder
    {
        $this->assertOwnsOrder($po, $user);

        return DB::transaction(function () use ($po, $user, $notes): PurchaseOrder {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertOwnsOrder($po, $user, verifySupplierInDatabase: true);
            if (! in_array($po->status, [PurchaseOrderStatus::SentToSupplier, PurchaseOrderStatus::SupplierAccepted], true)) {
                throw new BusinessException("لا يمكن طلب تعديل على أمر شراء في حالة ({$po->status->value}).");
            }
            if ($po->deliveries()->where('status', SupplierDeliveryStatus::PendingReceipt)->exists()) {
                throw new BusinessException('لا يمكن طلب تعديل وأمر الشراء له توريد بانتظار الاستلام.');
            }
            if ($po->items()->where('received_qty', '>', 0)->exists()
                || $po->deliveries()->whereHas('items', fn ($q) => $q->where('received_qty', '>', 0))->exists()) {
                throw new BusinessException('لا يمكن طلب تعديل بعد بدء استلام البضاعة.');
            }
            if ($po->invoices()->where('status', '!=', SupplierInvoiceStatus::Cancelled)->exists()) {
                throw new BusinessException('لا يمكن طلب تعديل وأمر الشراء عليه فواتير غير ملغاة.');
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
     * @param  array{delivery_date: string, supplier_invoice_no?: string|null, notes?: string|null, items: list<array{purchase_order_item_id: int, shipped_qty: float|int|string}>}  $data
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

        // Hash the temp upload before PrivateAttachments moves it into its prepared path.
        $invoiceHash = null;
        if ($invoiceFile !== null) {
            $path = $invoiceFile->getRealPath();
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                throw new BusinessException('تعذر قراءة ملف فاتورة التوريد المرفق.');
            }
            $invoiceHash = hash_file('sha256', $path);
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return PrivateAttachments::transaction(fn (PrivateAttachments $files) => app(OperationIdempotencyService::class)->run('supplier_delivery', $user, $idempotencyKey,
            ['purchase_order_id' => $po->id, ...$data, 'invoice_hash' => $invoiceHash],
            fn () => $this->executeSupplierDelivery($po, $user, $data, $invoiceFile, $files)), [[$invoiceFile, 'supplier_deliveries/pending']]);
    }

    private function executeSupplierDelivery(PurchaseOrder $po, SupplierUser $user, array $data, ?UploadedFile $invoiceFile, PrivateAttachments $files): SupplierDelivery
    {
        $delivery = DB::transaction(function () use ($po, $user, $data, $invoiceFile, $files): SupplierDelivery {
            $po = $this->lockSupplierThenOrder($po);
            $this->assertOwnsOrder($po, $user, verifySupplierInDatabase: true);
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
                $lines[$itemId] = (string) BigDecimal::of((string) ($lines[$itemId] ?? '0'))
                    ->plus((string) $line['shipped_qty'])->toScale(4, RoundingMode::HalfUp);
            }

            $poItems = $po->items()->with('variant:id,sku')->whereIn('id', array_keys($lines))->lockForUpdate()->get()->keyBy('id');
            if ($poItems->count() !== count($lines)) {
                throw new BusinessException('أحد بنود التوريد لا يتبع أمر الشراء هذا.');
            }
            foreach ($lines as $itemId => $shippedQty) {
                $poItem = $poItems->get($itemId);
                $pendingRows = SupplierDeliveryItem::query()
                    ->where('purchase_order_item_id', $itemId)
                    ->whereHas('delivery', fn ($q) => $q->where('status', SupplierDeliveryStatus::PendingReceipt->value))
                    ->lockForUpdate()
                    ->get(['shipped_qty', 'received_qty']);
                $pendingShipment = '0';
                foreach ($pendingRows as $pendingRow) {
                    $pendingShipment = (string) BigDecimal::of($pendingShipment)
                        ->plus(BigDecimal::of((string) $pendingRow->shipped_qty)->minus((string) $pendingRow->received_qty))
                        ->toScale(4, RoundingMode::HalfUp);
                }
                $used = BigDecimal::of((string) $poItem->received_qty)->plus((string) $pendingShipment)->plus((string) $shippedQty)->toScale(4, RoundingMode::HalfUp);
                if ($used->compareTo(BigDecimal::of((string) $poItem->ordered_qty)->toScale(4, RoundingMode::HalfUp)) > 0) {
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
    private function assertOwnsOrder(PurchaseOrder $po, SupplierUser $user, bool $verifySupplierInDatabase = false): void
    {
        if ((int) $po->supplier_id !== (int) $user->supplier_id) {
            throw new BusinessException('أمر الشراء غير موجود.', [], 404);
        }
        $supplierIsActive = $verifySupplierInDatabase || ! $user->relationLoaded('supplier')
            ? Supplier::query()->whereKey($user->supplier_id)->where('is_active', true)
                ->when($verifySupplierInDatabase, fn ($query) => $query->lockForUpdate())->first() !== null
            : (bool) $user->supplier?->is_active;
        if (! $user->is_active || ! $supplierIsActive) {
            throw new BusinessException('حساب المورد غير نشط.', [], 403);
        }
        $this->assertActiveBranch((int) $po->branch_id, 'فرع أمر الشراء غير نشط.', $verifySupplierInDatabase);
    }

    private function assertActiveSupplier(int $supplierId, bool $lockForUpdate = false): void
    {
        $supplier = Supplier::query()->whereKey($supplierId)->where('is_active', true)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($supplier === null) {
            throw new BusinessException('المورد غير نشط.');
        }
    }

    private function assertActiveBranch(int $branchId, string $message = 'فرع الاستلام غير نشط.', bool $lockForUpdate = false): void
    {
        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($branch === null) {
            throw new BusinessException($message);
        }
    }

    /** Lock and validate each selected product variant before attaching procurement items. */
    private function assertActiveVariants(array $variantIds): void
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return;
        }

        $variants = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        if ($variants->count() !== count($variantIds) || $variants->contains(fn (ProductVariant $variant): bool => ! $variant->is_active)) {
            throw new BusinessException('لا يمكن شراء متغير صنف غير نشط أو محذوف.');
        }
    }
}
