<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Orders\ReturnStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\WalletService;
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\DiscreteQuantity;
use App\Services\Shared\PrivateAttachments;
use App\Services\Shared\ProportionalDistribution;
use App\Services\Shared\SettingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Return Merchandise Authorization (RMA) service managing the four-stage return lifecycle.
 *
 * Lifecycle stages:
 * 1. create: Submission (validates delivery status, return window, and item quantity ceilings).
 * 2. approve: Administrative pre-approval (assigns receiving branch and approved item quantities).
 * 3. receive: Physical warehouse inspection and stock ledger intake via InventoryService.
 * 4. refund: Financial reimbursement (customer wallet credit or payment gateway reference).
 *
 * Golden Rule: Requests and approvals never alter stock balances. Only the physical intake
 * stage (receive) records ledger movements: sale_return for intact goods into on_hand,
 * followed by damage bucket allocation for defective units.
 */
class ReturnService
{
    private const ATTACHMENT_DIRECTORY = 'return_requests/items';

    public function __construct(
        protected SettingService $settings,
        protected InventoryService $inventory,
        protected OrderService $orders,
    ) {}

    /**
     * Submits a new return request for a delivered order within the allowed return window.
     *
     * @param  array{reason: string, refund_method?: string|null, items: list<array{order_item_id: int, requested_qty: float|int|string, item_reason?: string|null, attachments?: list<UploadedFile>}>}  $data
     */
    public function createReturnRequest(Order $order, array $data, ?Customer $customer = null): ReturnRequest
    {
        if ($data['items'] === []) {
            throw new BusinessException('طلب الإرجاع يجب أن يتضمن بندًا واحدًا على الأقل.');
        }
        // Lightweight pre-flight check before uploading attachments to R2.
        $preflightOrder = Order::query()->findOrFail($order->id);
        $this->assertOrderEligibleForReturn($preflightOrder, $customer);

        $uploads = [];
        foreach ($data['items'] as $item) {
            foreach ($item['attachments'] ?? [] as $file) {
                $uploads[] = [$file, self::ATTACHMENT_DIRECTORY];
            }
        }
        $return = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($order, $data, $customer): ReturnRequest {
            // Lock order row to serialize concurrent return requests against line item quantities.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertOrderEligibleForReturn($order, $customer);

            // Aggregate duplicate return lines for the same order item before validating ceilings.
            $lines = [];
            foreach ($data['items'] as $line) {
                DiscreteQuantity::assert($line['requested_qty']);
                $orderItemId = (int) $line['order_item_id'];
                $lines[$orderItemId] ??= ['qty' => 0.0, 'reason' => null, 'files' => []];
                $lines[$orderItemId]['qty'] += (float) $line['requested_qty'];
                $lines[$orderItemId]['reason'] = $line['item_reason'] ?? $lines[$orderItemId]['reason'];
                $lines[$orderItemId]['files'] = array_merge($lines[$orderItemId]['files'], $line['attachments'] ?? []);
            }

            $orderItems = $order->items()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            if ($orderItems->count() !== count($lines)) {
                throw new BusinessException('أحد البنود المطلوب إرجاعها لا يتبع هذا الطلب.');
            }

            foreach ($lines as $orderItemId => $line) {
                $orderItem = $orderItems->get($orderItemId);
                // Active return requests (excluding rejected/cancelled) count against remaining returnable quantity.
                $previouslyRequested = (float) ReturnRequestItem::query()
                    ->where('order_item_id', $orderItemId)
                    ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                    ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
                    ->selectRaw("COALESCE(SUM(CASE WHEN return_requests.status IN ('received', 'refunded') THEN received_good_qty + received_damaged_qty WHEN return_requests.status = 'approved' THEN approved_qty ELSE requested_qty END), 0) as total")
                    ->value('total');
                if (round($previouslyRequested + $line['qty'], 4) > round((float) $orderItem->qty, 4)) {
                    throw new BusinessException("كمية الإرجاع للصنف ({$orderItem->sku_snapshot}) تتجاوز الكمية المشتراة بعد احتساب مرتجعات سابقة.");
                }
            }

            $return = ReturnRequest::create([
                'return_number' => $this->settings->generateNextNumber('return'),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'status' => ReturnStatus::Pending,
                'refund_method' => $data['refund_method'] ?? null,
                'total_refund_amount' => 0,
                'reason' => $data['reason'],
            ]);

            foreach ($lines as $orderItemId => $line) {
                $orderItem = $orderItems->get($orderItemId);
                $return->items()->create([
                    'order_item_id' => $orderItem->id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'requested_qty' => $line['qty'],
                    'unit_refund_price' => $this->netUnitRefundPrice($orderItem),
                    'item_reason' => $line['reason'],
                ]);
            }

            $this->storeAttachments($return, $data['items'], $files);

            return $return;
        }, $uploads);

        return $return->fresh(['items']);
    }

    /**
     * Grants administrative pre-approval, designating receiving branch and approved item quantities.
     *
     * Does not alter inventory balances until physical intake.
     *
     * @param  array{branch_id: int, admin_notes?: string|null, items?: list<array{id: int, approved_qty: float|int|string}>}  $data
     */
    public function approve(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('لا يمكن اعتماد طلب مرتجع إلا وهو قيد المراجعة.');
            }

            if (! Branch::query()->whereKey($data['branch_id'])->where('is_active', true)->exists()) {
                throw new BusinessException('فرع الاستلام غير نشط.');
            }
            $return->load(['items.orderItem', 'items.variant.product']);
            $approved = collect($data['items'] ?? [])->keyBy(fn (array $line): int => (int) $line['id']);
            foreach ($approved->keys() as $itemId) {
                if (! $return->items->contains('id', $itemId)) {
                    throw new BusinessException('أحد البنود المعتمدة لا يتبع طلب المرتجع هذا.');
                }
            }

            $totalApproved = 0.0;
            foreach ($return->items as $item) {
                // Items omitted from approval payload default to their full requested quantity.
                $qty = $approved->has($item->id)
                    ? (float) $approved->get($item->id)['approved_qty']
                    : (float) $item->requested_qty;
                DiscreteQuantity::assert($qty, true);
                if ($qty < 0 || round($qty, 4) > round((float) $item->requested_qty, 4)) {
                    throw new BusinessException("الكمية المعتمدة للصنف ({$item->orderItem?->sku_snapshot}) يجب أن تكون بين صفر والمطلوب.");
                }
                $item->update(['approved_qty' => $qty]);
                $totalApproved += $qty;
            }
            if ($totalApproved <= 0) {
                throw new BusinessException('لا توجد كميات معتمدة؛ ارفض الطلب بدلًا من اعتماده إن لم يُقبل أي بند.');
            }

            $return->update([
                'branch_id' => (int) $data['branch_id'],
                'status' => ReturnStatus::Approved,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'admin_notes' => $data['admin_notes'] ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Performs physical inspection and records inventory intake movements into warehouse stock.
     *
     * @param  array{items: list<array{id: int, received_good_qty: float|int|string, received_damaged_qty: float|int|string}>}  $data
     */
    public function receive(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Approved) {
                throw new BusinessException('لا يمكن استلام مرتجع إلا بعد الموافقة المبدئية عليه.');
            }
            if ($return->branch_id === null) {
                throw new BusinessException('لم يُحدد فرع الاستلام عند اعتماد هذا المرتجع.');
            }
            if (! Branch::query()->whereKey($return->branch_id)->where('is_active', true)->exists()) {
                throw new BusinessException('فرع الاستلام غير نشط.');
            }

            $return->load(['items.orderItem', 'items.variant.product']);
            $lines = collect($data['items'])->keyBy(fn (array $line): int => (int) $line['id']);
            foreach ($lines->keys() as $itemId) {
                if (! $return->items->contains('id', $itemId)) {
                    throw new BusinessException('أحد البنود المستلمة لا يتبع طلب المرتجع هذا.');
                }
            }
            foreach ($return->items as $item) {
                if (! $lines->has($item->id)) {
                    throw new BusinessException('بيانات الاستلام يجب أن تغطي كل بنود المرتجع (بند بلا استلام فعلي يُرسل بصفر).');
                }
            }
            $receivedTotal = 0.0;
            foreach ($return->items as $item) {
                $line = $lines->get($item->id);
                $receivedTotal += (float) $line['received_good_qty'] + (float) $line['received_damaged_qty'];
            }
            if ($receivedTotal <= 0) {
                throw new BusinessException('لا يمكن تثبيت استلام كميته صفر؛ ألغِ المرتجع بدلًا من ذلك.');
            }

            // Aggregate prior returned quantities across order items in a single query to avoid N+1 queries.
            $previousByOrderItem = ReturnRequestItem::query()
                ->whereIn('order_item_id', $return->items->pluck('order_item_id')->all())
                ->whereHas('returnRequest', fn ($q) => $q->whereIn('status', [ReturnStatus::Received, ReturnStatus::Refunded]))
                ->selectRaw('order_item_id, COALESCE(SUM(received_good_qty + received_damaged_qty), 0) as qty, COALESCE(SUM(refund_amount), 0) as refunded')
                ->groupBy('order_item_id')
                ->get()
                ->keyBy('order_item_id');

            $locationId = $this->inventory->receivingLocation((int) $return->branch_id);
            $levels = $this->inventory->lockLevels($return->items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'branch_id' => (int) $return->branch_id, 'location_id' => $locationId,
            ])->all());
            $costs = StockMovement::query()->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
                ->where('reference_id', $order->id)->where('type', StockMovementType::Sale)
                ->whereNotNull('unit_cost')->orderBy('id')->pluck('unit_cost', 'product_variant_id');

            $refundTotal = 0.0;
            foreach ($return->items as $item) {
                $line = $lines->get($item->id);
                DiscreteQuantity::assert($line['received_good_qty'], true);
                DiscreteQuantity::assert($line['received_damaged_qty'], true);
                $good = (float) $line['received_good_qty'];
                $damaged = (float) $line['received_damaged_qty'];
                if (round($good + $damaged, 4) > round((float) $item->approved_qty, 4)) {
                    throw new BusinessException("المستلم للصنف ({$item->orderItem?->sku_snapshot}) يتجاوز الكمية المعتمدة.");
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => (int) $return->branch_id,
                    'location_id' => $locationId,
                    'unit_cost' => $costs->get($item->product_variant_id),
                    'reference_type' => ReturnRequest::class,
                    'reference_id' => $return->id,
                    'created_by' => $admin->id,
                ];
                $level = $levels->get($this->inventory->levelKey($base));
                if ($good > 0) {
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $good,
                        'notes' => "مرتجع سليم — طلب {$return->return_number}",
                    ], $level);
                }
                if ($damaged > 0) {
                    // Damaged units return physically to on_hand before being segregated into the damaged bucket.
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف (عودة فيزيائية) — طلب {$return->return_number}",
                    ], $level);
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::Damage,
                        'affected_bucket' => StockBucket::Damaged,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف بعد الفحص — طلب {$return->return_number}",
                    ], $level);
                }

                $receivedTotal = $good + $damaged;
                if ($receivedTotal > 0 && $item->variant?->product_id) {
                    Product::query()->whereKey($item->variant->product_id)
                        ->update(['sales_count' => DB::raw('GREATEST(sales_count - '.(int) round($receivedTotal).', 0)')]);
                }

                $previous = $previousByOrderItem->get($item->order_item_id);
                $previousQty = (float) ($previous->qty ?? 0);
                $previousRefunded = (float) ($previous->refunded ?? 0);
                $orderItem = $item->orderItem;
                $netCents = $this->netLineRefundCents($orderItem);
                $cumulativeQty = $previousQty + $receivedTotal;
                $orderItem->update(['returned_qty' => $cumulativeQty]);
                $orderQty = (float) $orderItem->qty;
                $targetCents = ($orderQty <= 0 || $cumulativeQty >= $orderQty)
                    ? $netCents
                    : min($netCents, (int) round($netCents * ($cumulativeQty / $orderQty)));
                $refundAmount = max(0, $targetCents - (int) round($previousRefunded * 100)) / 100;
                $item->update([
                    'refund_amount' => $refundAmount,
                    'received_good_qty' => $good,
                    'received_damaged_qty' => $damaged,
                ]);
                $refundTotal += $refundAmount;
            }

            $return->update([
                'total_refund_amount' => round($refundTotal, 2),
                'status' => ReturnStatus::Received,
                'received_by' => $admin->id,
                'received_at' => now(),
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Issues financial reimbursement to the customer via wallet credit or recorded payment gateway reference.
     *
     * @param  array{refund_method?: string|null, refund_reference?: string|null, treasury_id?: int|null}  $data
     */
    public function refund(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            // Match payment approval's customer -> order lock order when the return can release credit.
            $customerId = Order::query()->whereKey($return->order_id)->value('customer_id');
            if ($customerId !== null) {
                Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            }
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Received) {
                throw new BusinessException('لا يمكن رد مبلغ مرتجع إلا بعد استلامه وفحصه.');
            }

            $method = $data['refund_method'] ?? $return->refund_method?->value;
            if ($method === null) {
                throw new BusinessException('حدد طريقة رد المبلغ قبل إتمام الاسترداد.');
            }
            $reference = $data['refund_reference'] ?? $return->refund_reference;
            if (in_array($method, [RefundMethod::OriginalPayment->value, RefundMethod::BankTransfer->value], true)
                && trim((string) $reference) === '') {
                throw new BusinessException('مرجع التحويل أو بوابة الدفع مطلوب لإتمام الاسترداد.');
            }

            $isCreditNote = $method === RefundMethod::CreditNote->value;
            if ($isCreditNote) {
                if ($order->payment_method !== 'credit' || ! in_array($order->payment_status, ['unpaid', 'paid'], true) || $order->status === OrderStatus::Cancelled) {
                    throw new BusinessException('الإشعار الدائن مخصص للطلبات الآجلة غير الملغاة.');
                }
                $noticeService = app(\App\Services\Wholesale\WholesalePaymentNoticeService::class);
                $noticeService->releaseExcessAllocationsForCreditNote($order, (string) $return->total_refund_amount, $admin->id, $return->id);
                $noticeService->assertCreditNoteCapacity($order, (string) $return->total_refund_amount, $return->id);
            } elseif (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
                throw new BusinessException('لا يمكن رد مبلغ نقدي أو إلى المحفظة لطلب غير مدفوع. يُقيَّد المرتجع كتخفيض للمستحق حتى يُبنى مسار إشعار دائن.');
            }
            if ($order->payment_method === 'wallet' && $method !== RefundMethod::Wallet->value) {
                throw new BusinessException('طلب مدفوع بالمحفظة يُرد إلى المحفظة فقط.');
            }
            if ($method === RefundMethod::OriginalPayment->value && $order->payment_method === 'cod') {
                throw new BusinessException('طلب الدفع عند الاستلام لا يُرد إلى بوابة الدفع.');
            }
            $confirmed = (int) round((float) $order->gateway_refunded_amount * 100);
            $used = (int) round((float) $order->returnRequests()->where('status', ReturnStatus::Refunded)
                ->where('refund_method', RefundMethod::OriginalPayment)->sum('total_refund_amount') * 100);
            $unallocatedGatewayCents = max(0, $confirmed - $used);
            if ($method === RefundMethod::OriginalPayment->value) {
                if ($confirmed <= 0 || $unallocatedGatewayCents < (int) round((float) $return->total_refund_amount * 100)) {
                    throw new BusinessException('سجّل الاسترداد في بوابة الدفع وانتظر تأكيدها قبل إقفال المرتجع.');
                }
            } elseif (! $isCreditNote && $unallocatedGatewayCents > 0) {
                throw new BusinessException('يوجد استرداد مؤكد في بوابة الدفع؛ اختر طريقة الدفع الأصلية لتوثيقه.');
            }
            $reference = $reference !== null ? trim((string) $reference) : $reference;
            if ($reference !== null && $reference !== '' && ReturnRequest::query()
                ->where('id', '!=', $return->id)
                ->whereNotIn('status', [ReturnStatus::Rejected, ReturnStatus::Cancelled])
                ->whereRaw('lower(btrim(refund_reference)) = lower(btrim(?))', [$reference])
                ->exists()) {
                throw new BusinessException('مرجع الرد مسجّل سابقًا.', [], 409);
            }

            if ($method === RefundMethod::Cash->value && (float) $return->total_refund_amount > 0) {
                $treasury = Treasury::query()->where('branch_id', $return->branch_id)
                    ->whereNotNull('branch_id')->where('type', TreasuryType::MainSafe)
                    ->where('is_active', true)->lockForUpdate()->find($data['treasury_id'] ?? null);
                if ($treasury === null) {
                    throw new BusinessException('اختر خزينة نقدية نشطة في فرع المرتجع.');
                }
                app(BranchAccess::class)->assertTreasury($admin->id, $treasury);
                app(TreasuryService::class)->record([
                    'treasury_id' => $treasury->id,
                    'type' => TreasuryTransactionType::OrderRefund,
                    'amount' => '-'.(string) $return->total_refund_amount,
                    'reference_type' => 'return_request',
                    'reference_id' => $return->id,
                    'created_by' => $admin->id,
                ]);
            } elseif ($method === RefundMethod::Wallet->value) {
                if ($return->customer_id === null) {
                    throw new BusinessException('الاسترداد إلى المحفظة يتطلب حساب عميل مسجل؛ اختر طريقة أخرى لطلبات الزوار.');
                }
                if ((float) $return->total_refund_amount > 0) {
                    app(WalletService::class)->record($return->customer_id, (string) $return->total_refund_amount,
                        'return_refund', 'return_request', $return->id, $admin->id);
                }
            }

            try {
                $return->update([
                    'refund_method' => $method,
                    'refund_reference' => $reference,
                    'status' => ReturnStatus::Refunded,
                    'refunded_by' => $admin->id,
                    'refunded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('مرجع الرد مسجّل سابقًا.', [], 409);
            }

            if ($isCreditNote) {
                app(\App\Services\Wholesale\WholesalePaymentNoticeService::class)->settleCreditNote($order, $admin->id, $return->return_number);
            } else {
                $this->syncOrderPaymentStatusAfterRefund($order);
            }
            if ($this->isOrderFullyReturned($order)) {
                $this->orders->changeStatus(
                    $order,
                    OrderStatus::Returned,
                    $admin,
                    "إرجاع كامل لبنود الطلب عبر طلب المرتجع {$return->return_number}",
                    null,
                    false,
                );
            }

            return $return->fresh(['items']);
        });
    }

    /**
     * Permanently deletes a pending return request and removes associated attachment files from private storage.
     */
    public function delete(ReturnRequest $return): void
    {
        DB::transaction(function () use ($return): void {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('يمكن حذف المرتجعات قيد المراجعة فقط؛ استخدم الإلغاء للحالات الأخرى.');
            }
            $paths = $return->items()->get()->flatMap(fn ($item) => $item->attachments ?? [])->all();
            $return->delete();
            DB::afterCommit(function () use ($paths): void {
                try {
                    Storage::disk('r2')->delete($paths);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        });
    }

    /**
     * Rejects a pending return request without modifying stock or treasury balances.
     */
    public function reject(ReturnRequest $return, User $admin, ?string $notes = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $notes): ReturnRequest {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('لا يمكن رفض طلب مرتجع إلا وهو قيد المراجعة.');
            }
            $return->update([
                'status' => ReturnStatus::Rejected,
                'admin_notes' => $notes ?? $return->admin_notes,
                'rejected_by' => $admin->id,
                'rejected_at' => now(),
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Cancels an unreceived return request (pending or approved).
     */
    public function cancel(ReturnRequest $return, ?string $notes = null, ?User $admin = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $notes, $admin): ReturnRequest {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [ReturnStatus::Pending, ReturnStatus::Approved], true)) {
                throw new BusinessException('لا يمكن إلغاء مرتجع بعد استلامه.');
            }
            $return->update([
                'status' => ReturnStatus::Cancelled,
                'admin_notes' => $notes ?? $return->admin_notes,
                'cancelled_by' => $admin?->id,
                'cancelled_at' => now(),
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Updates non-financial return metadata, routing rejected and cancelled transitions to dedicated handlers.
     *
     * @param  array{admin_notes?: string|null, refund_method?: string|null, status?: string|null}  $data
     */
    public function updateDetails(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        if (array_key_exists('status', $data) && $data['status'] !== null
            && ! in_array($data['status'], [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value], true)) {
            throw new BusinessException('حالة المرتجع لا تُغيَّر من هنا إلا إلى مرفوض أو ملغى.');
        }
        if (($data['status'] ?? null) === ReturnStatus::Rejected->value) {
            return $this->reject($return, $admin, $data['admin_notes'] ?? null);
        }
        if (($data['status'] ?? null) === ReturnStatus::Cancelled->value) {
            return $this->cancel($return, $data['admin_notes'] ?? null, $admin);
        }

        return DB::transaction(function () use ($return, $data): ReturnRequest {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [ReturnStatus::Pending, ReturnStatus::Approved, ReturnStatus::Received], true)
                && array_key_exists('refund_method', $data)) {
                throw new BusinessException('لا يمكن تغيير طريقة الرد بعد اكتمال الاسترداد.');
            }

            $updates = [];
            if (array_key_exists('admin_notes', $data)) {
                $updates['admin_notes'] = $data['admin_notes'];
            }
            if (array_key_exists('refund_method', $data)) {
                $updates['refund_method'] = $data['refund_method'];
            }
            if ($updates !== []) {
                $return->update($updates);
            }

            return $return->fresh(['items']);
        });
    }

    private function assertOrderEligibleForReturn(Order $order, ?Customer $customer): void
    {
        if ($customer !== null && (int) $order->customer_id !== (int) $customer->id) {
            throw new BusinessException('هذا الطلب لا يخص حسابك.', [], 403);
        }

        if ($order->status !== OrderStatus::Delivered) {
            throw new BusinessException('لا يمكن إنشاء طلب إرجاع إلا لطلب تم تسليمه فعليًا.');
        }
        if ($order->channel === 'pos') {
            throw new BusinessException('مرتجعات نقطة البيع تُنفَّذ من مسار POS لمنع تكرار الرد بين مسارين.');
        }

        $this->assertWithinReturnWindow($order);
    }

    /**
     * Validates that the return request is submitted within the configured days since delivery.
     */
    private function assertWithinReturnWindow(Order $order): void
    {
        $windowDays = (int) $this->settings->get('return_window_days', 14);
        if ($windowDays === 0) {
            throw new BusinessException('الإرجاع متوقف من إعدادات المتجر.');
        }
        if ($windowDays < 0) {
            return;
        }

        $deliveredAt = $order->statusHistories()
            ->where('to_status', OrderStatus::Delivered->value)
            ->value('created_at');

        if ($deliveredAt === null) {
            throw new BusinessException('لا يوجد سجل تسليم موثوق لحساب فترة الاسترجاع.');
        }

        $timezone = config('app.business_timezone');
        $tz = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
        if (Carbon::parse($deliveredAt)->timezone($tz)->addDays($windowDays)->endOfDay()->isPast()) {
            throw new BusinessException("انتهت فترة الاسترجاع المسموحة ({$windowDays} يومًا من التسليم) لهذا الطلب.");
        }
    }

    /**
     * Computes effective net unit refund price after proportional discounts and non-inclusive taxes.
     */
    private function netUnitRefundPrice(OrderItem $orderItem): float
    {
        $qty = (float) $orderItem->qty;
        if ($qty <= 0) {
            return (float) $orderItem->unit_price;
        }

        return round($this->netLineRefundCents($orderItem) / 100 / $qty, 4);
    }

    /**
     * Computes net payable amount in integer cents for an order item (unit_price * qty - discounts + item taxes).
     */
    private function netLineRefundCents(OrderItem $orderItem): int
    {
        if ($orderItem->coupon_discount_amount !== null) {
            $net = BigDecimal::of((string) $orderItem->line_total)
                ->minus((string) $orderItem->coupon_discount_amount)
                ->plus((string) $orderItem->tax_amount);
            $cents = $net->multipliedBy('100')->toScale(0, RoundingMode::HalfUp)->toInt();

            return max(0, $cents);
        }

        $order = $orderItem->order;
        $usesUnitPriceAsEffective = abs((float) $orderItem->line_total - ((float) $orderItem->unit_price * (float) $orderItem->qty)) < 0.01;
        $itemMerchandise = $usesUnitPriceAsEffective
            ? BigDecimal::of((string) $orderItem->line_total)
            : BigDecimal::of((string) $orderItem->unit_price)
                ->multipliedBy((string) $orderItem->qty)
                ->minus((string) $orderItem->discount_amount);

        if ($order !== null && (float) $order->discount_amount > 0) {
            $orderItems = $order->items()->orderBy('id')->get();
            if ($orderItems->isNotEmpty()) {
                $pricingDiscountSum = $orderItems->sum(
                    fn (OrderItem $i): float => abs((float) $i->line_total - ((float) $i->unit_price * (float) $i->qty)) < 0.01
                        ? (float) $i->discount_amount
                        : 0.0
                );
                $couponToDistribute = max(0.0, round((float) $order->discount_amount - $pricingDiscountSum, 2));
                if ($couponToDistribute > 0) {
                    $weights = $orderItems->map(
                        fn (OrderItem $i): float => max(0.0, (float) $i->line_total)
                    )->all();
                    $discountShares = ProportionalDistribution::distribute(
                        min($couponToDistribute, array_sum($weights)),
                        $weights
                    );
                    $index = $orderItems->search(fn (OrderItem $i): bool => (int) $i->id === (int) $orderItem->id);
                    if ($index !== false) {
                        $itemMerchandise = $itemMerchandise->minus((string) $discountShares[$index]);
                    }
                }
            }
        }

        $net = $itemMerchandise->plus((string) $orderItem->tax_amount);
        $cents = $net->multipliedBy('100')->toScale(0, RoundingMode::HalfUp)->toInt();

        return max(0, $cents);
    }

    /**
     * Updates order payment status to refunded or partially_refunded based on cumulative return reimbursements.
     */
    private function syncOrderPaymentStatusAfterRefund(Order $order): void
    {
        if (! in_array($order->payment_status, ['paid', 'partially_refunded'], true)) {
            return;
        }
        $confirmedGatewayCents = (int) round((float) $order->gateway_refunded_amount * 100);
        $usedGatewayCents = (int) round((float) $order->returnRequests()
            ->where('status', ReturnStatus::Refunded)
            ->where('refund_method', RefundMethod::OriginalPayment)
            ->sum('total_refund_amount') * 100);
        $unallocatedGatewayCents = max(0, $confirmedGatewayCents - $usedGatewayCents);
        $refundedReturnsCents = (int) round((float) $order->returnRequests()
            ->where('status', ReturnStatus::Refunded)
            ->sum('total_refund_amount') * 100);
        $totalRefundedCents = $unallocatedGatewayCents + $refundedReturnsCents;
        $refundableCents = (int) $order->items()->get()->sum(fn (OrderItem $item): int => $this->netLineRefundCents($item));
        if ($refundableCents > 0 && $totalRefundedCents >= $refundableCents) {
            $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
        } elseif ($totalRefundedCents > 0) {
            $order->update(['payment_status' => 'partially_refunded']);
        }
    }

    /**
     * Stores uploaded defect photos on private disk and updates item attachment paths.
     *
     * @param  list<array{order_item_id: int, attachments?: list<UploadedFile>}>  $rawItems
     */
    private function storeAttachments(ReturnRequest $return, array $rawItems, PrivateAttachments $files): void
    {
        $filesByOrderItem = [];
        foreach ($rawItems as $line) {
            foreach ($line['attachments'] ?? [] as $file) {
                if ($file instanceof UploadedFile) {
                    $filesByOrderItem[(int) $line['order_item_id']][] = $file;
                }
            }
        }
        if ($filesByOrderItem === []) {
            return;
        }

        $return->load('items');
        foreach ($return->items as $item) {
            $itemFiles = $filesByOrderItem[(int) $item->order_item_id] ?? [];
            if ($itemFiles === []) {
                continue;
            }
            $paths = [];
            foreach ($itemFiles as $file) {
                $paths[] = $files->store($file, self::ATTACHMENT_DIRECTORY);
            }
            $item->update(['attachments' => array_merge($item->attachments ?? [], $paths)]);
        }
    }

    /**
     * Checks whether all ordered items have been fully received and refunded.
     */
    private function isOrderFullyReturned(Order $order): bool
    {
        $items = $order->items()->get();
        if ($items->isEmpty()) {
            return false;
        }

        $receivedByItem = ReturnRequestItem::query()
            ->whereIn('order_item_id', $items->pluck('id'))
            ->whereHas('returnRequest', fn ($q) => $q->where('status', ReturnStatus::Refunded->value))
            ->selectRaw('order_item_id, COALESCE(SUM(received_good_qty + received_damaged_qty), 0) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id');
        foreach ($items as $orderItem) {
            $received = (float) ($receivedByItem[$orderItem->id] ?? 0);
            if (round($received, 4) < round((float) $orderItem->qty, 4)) {
                return false;
            }
        }

        return true;
    }
}
