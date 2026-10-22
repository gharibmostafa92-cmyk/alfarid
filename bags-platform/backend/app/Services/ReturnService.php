<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * خدمة المرتجعات (RMA) — دورة حياة طلب الإرجاع في أربع خطوات:
 * create ← تقديم الطلب (تحقق من التسليم ونافذة الاسترجاع وسقف الكميات)،
 * approve ← موافقة مبدئية (فرع مستلم وكميات معتمدة)،
 * receive ← استلام وفحص فعلي، refund ← رد مالي للعميل.
 *
 * القاعدة الذهبية: الإنشاء والاعتماد لا يضيفان أي رصيد للمخزون. الإضافة
 * الفعلية تتم حصريًا في receive عبر InventoryService::recordMovement:
 * السليم يعود إلى on_hand بحركة sale_return، والتالف يُسجل في وعاء
 * damaged بحركة damage (بعد عودته فيزيائيًا إلى on_hand بنفس الكمية،
 * لأن وعاء التالف شريحة من الرصيد الفعلي لا رصيد مستقل).
 */
class ReturnService
{
    public function __construct(
        protected SettingService $settings,
        protected InventoryService $inventory,
        protected OrderService $orders,
    ) {}

    /**
     * تقديم طلب إرجاع لطلب مُسلَّم داخل نافذة الاسترجاع.
     * $customer عند الإنشاء من المتجر (يفرض ملكية الطلب)؛ null عند إنشاء
     * الأدمن اليدوي لطلب أي عميل/زائر.
     *
     * @param  array{reason: string, refund_method?: string|null, items: list<array{order_item_id: int, requested_qty: float|int|string, item_reason?: string|null, attachments?: list<UploadedFile>}>}  $data
     */
    public function createReturnRequest(Order $order, array $data, ?Customer $customer = null): ReturnRequest
    {
        $return = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($order, $data, $customer): ReturnRequest {
            // قفل صف الطلب: طلبا إرجاع متزامنان لنفس البند لا يتجاوزا سقفه معًا
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($customer !== null && (int) $order->customer_id !== (int) $customer->id) {
                throw new BusinessException('هذا الطلب لا يخص حسابك.', [], 403);
            }

            if ($order->status !== OrderStatus::Delivered) {
                throw new BusinessException('لا يمكن إنشاء طلب إرجاع إلا لطلب تم تسليمه فعليًا.');
            }

            $this->assertWithinReturnWindow($order);

            // تجميع البنود المكررة لنفس سطر الطلب قبل فحص السقف
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
                // ما سبق طلبه للإرجاع في طلبات قائمة (غير المرفوض/الملغي)
                // يحجز من سقف البند حتى لا يُرجع أكثر مما اشتراه العميل
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
        });

        return $return->fresh(['items']);
    }

    /**
     * الموافقة المبدئية: تحديد الفرع المستلم والكميات المعتمدة لكل بند.
     * لا تلمس المخزون بأي شكل — الاستلام الفعلي لاحقًا هو ما يحرّكه.
     *
     * @param  array{branch_id: int, admin_notes?: string|null, items?: list<array{id: int, approved_qty: float|int|string}>}  $data
     */
    public function approve(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
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
                // بند غير مذكور في الحمولة يُعتمد بكامل كميته المطلوبة
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
     * الاستلام والفحص المخزني — الخطوة الوحيدة التي تحرّك المخزون:
     * السليم sale_return إلى on_hand، والتالف damage إلى وعاء damaged،
     * داخل معاملة واحدة عبر InventoryService حصرًا.
     *
     * @param  array{items: list<array{id: int, received_good_qty: float|int|string, received_damaged_qty: float|int|string}>}  $data
     */
    public function receive(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            Order::query()->lockForUpdate()->findOrFail($return->order_id);
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

            // تجميعات المرتجعات السابقة (مستلمة/مردودة) لكل بنود الطلب في
            // استعلام واحد مجمّع بدل استعلام لكل بند داخل الحلقة (L5). بنود
            // هذا المرتجع نفسه مستبعدة ضمنيًا لأن حالته ما زالت Approved.
            $previousByOrderItem = ReturnRequestItem::query()
                ->whereIn('order_item_id', $return->items->pluck('order_item_id')->all())
                ->whereHas('returnRequest', fn ($q) => $q->whereIn('status', [ReturnStatus::Received, ReturnStatus::Refunded]))
                ->selectRaw('order_item_id, COALESCE(SUM(received_good_qty + received_damaged_qty), 0) as qty, COALESCE(SUM(refund_amount), 0) as refunded')
                ->groupBy('order_item_id')
                ->get()
                ->keyBy('order_item_id');

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
                    'location_id' => null,
                    'reference_type' => ReturnRequest::class,
                    'reference_id' => $return->id,
                    'created_by' => $admin->id,
                ];
                if ($good > 0) {
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $good,
                        'notes' => "مرتجع سليم — طلب {$return->return_number}",
                    ]);
                }
                if ($damaged > 0) {
                    // القطعة التالفة عادت فيزيائيًا للمخزن فتدخل on_hand أولًا،
                    // ثم تُحجز في وعاء التالف — المتاح للبيع لا يزيد بها
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف (عودة فيزيائية) — طلب {$return->return_number}",
                    ]);
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::Damage,
                        'affected_bucket' => StockBucket::Damaged,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف بعد الفحص — طلب {$return->return_number}",
                    ]);
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
                $targetCents = $cumulativeQty >= (float) $orderItem->qty
                    ? $netCents
                    : min($netCents, (int) round($cumulativeQty * (float) $item->unit_refund_price * 100));
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
     * رد المبلغ للعميل بعد الاستلام: محفظة (تُضاف لرصيد العميل) أو تسجيل
     * مرجع تحويل/بوابة. اكتمال إرجاع كل بنود الطلب ينقل الطلب نفسه إلى
     * Returned عبر بوابة الحالة بلا آثار مخزون/استرداد مكررة.
     *
     * @param  array{refund_method?: string|null, refund_reference?: string|null}  $data
     */
    public function refund(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
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

            if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
                throw new BusinessException('لا يمكن رد مبلغ نقدي أو إلى المحفظة لطلب غير مدفوع. يُقيَّد المرتجع كتخفيض للمستحق حتى يُبنى مسار إشعار دائن.');
            }
            if ($order->payment_method === 'wallet' && $method !== RefundMethod::Wallet->value) {
                throw new BusinessException('طلب مدفوع بالمحفظة يُرد إلى المحفظة فقط.');
            }
            $reference = $reference !== null ? trim((string) $reference) : $reference;
            if ($reference !== null && $reference !== '' && ReturnRequest::query()
                ->where('id', '!=', $return->id)
                ->whereNotIn('status', [ReturnStatus::Rejected, ReturnStatus::Cancelled])
                ->whereRaw('lower(btrim(refund_reference)) = ?', [mb_strtolower($reference)])
                ->exists()) {
                throw new BusinessException('مرجع الرد مسجّل سابقًا.', [], 409);
            }

            if ($method === RefundMethod::Wallet->value) {
                if ($return->customer_id === null) {
                    throw new BusinessException('الاسترداد إلى المحفظة يتطلب حساب عميل مسجل؛ اختر طريقة أخرى لطلبات الزوار.');
                }
                app(WalletService::class)->record($return->customer_id, (float) $return->total_refund_amount,
                    'return_refund', 'return_request', $return->id, $admin->id);
            }

            $return->update([
                'refund_method' => $method,
                'refund_reference' => $reference,
                'status' => ReturnStatus::Refunded,
                'refunded_by' => $admin->id,
                'refunded_at' => now(),
            ]);

            $this->syncOrderPaymentStatusAfterRefund($order);
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

    public function delete(ReturnRequest $return): void
    {
        DB::transaction(function () use ($return): void {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('يمكن حذف المرتجعات قيد المراجعة فقط؛ استخدم الإلغاء للحالات الأخرى.');
            }
            $paths = $return->items()->get()->flatMap(fn ($item) => $item->attachments ?? [])->all();
            $return->delete();
            DB::afterCommit(function () use ($paths): void {
                Storage::disk('r2')->delete($paths);
            });
        });
    }

    /** رفض طلب قيد المراجعة — بلا أي أثر (لم يتحرك مخزون ولا مال). */
    public function reject(ReturnRequest $return, User $admin, ?string $notes = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $notes): ReturnRequest {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('لا يمكن رفض طلب مرتجع إلا وهو قيد المراجعة.');
            }
            $return->update([
                'status' => ReturnStatus::Rejected,
                'admin_notes' => $notes ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /** إلغاء طلب لم يُستلم بعد (قيد المراجعة/معتمد) — بلا أي أثر مخزني. */
    public function cancel(ReturnRequest $return, ?string $notes = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $notes): ReturnRequest {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [ReturnStatus::Pending, ReturnStatus::Approved], true)) {
                throw new BusinessException('لا يمكن إلغاء مرتجع بعد استلامه.');
            }
            $return->update([
                'status' => ReturnStatus::Cancelled,
                'admin_notes' => $notes ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * تعديلات إدارية بلا آثار جانبية: ملاحظات وطريقة رد (قبل اكتمال الرد)،
     * وتوجيه حالتي rejected/cancelled لمساريهما في الخدمة.
     *
     * @param  array{admin_notes?: string|null, refund_method?: string|null, status?: string|null}  $data
     */
    public function updateDetails(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        if (($data['status'] ?? null) === ReturnStatus::Rejected->value) {
            return $this->reject($return, $admin, $data['admin_notes'] ?? null);
        }
        if (($data['status'] ?? null) === ReturnStatus::Cancelled->value) {
            return $this->cancel($return, $data['admin_notes'] ?? null);
        }

        return DB::transaction(function () use ($return, $data): ReturnRequest {
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

    /**
     * نافذة الاسترجاع من الإعدادات (أيام من لحظة انتقال الطلب إلى delivered).
     * 0 = الإرجاع متوقف، قيمة سالبة (مثل -1) = بلا حد زمني.
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
            ->latest('id')
            ->value('created_at');

        if ($deliveredAt === null) {
            throw new BusinessException('لا يوجد سجل تسليم موثوق لحساب فترة الاسترجاع.');
        }

        if ($deliveredAt !== null && $deliveredAt->copy()->addDays($windowDays)->isPast()) {
            throw new BusinessException("انتهت فترة الاسترجاع المسموحة ({$windowDays} يومًا من التسليم) لهذا الطلب.");
        }
    }

    /** سعر وحدة الرد الصافي: ما دفعه العميل فعليًا للبند ÷ كميته (بعد خصومات الأسعار وحصة الكوبون والضريبة غير المشمولة). */
    private function netUnitRefundPrice(OrderItem $orderItem): float
    {
        $qty = (float) $orderItem->qty;
        if ($qty <= 0) {
            return (float) $orderItem->unit_price;
        }

        return round($this->netLineRefundCents($orderItem) / 100 / $qty, 4);
    }

    /** صافي البند بالسنت: السعر × الكمية − الخصم + ضريبة البند (صفر عندما تكون الأسعار شاملة الضريبة). */
    private function netLineRefundCents(OrderItem $orderItem): int
    {
        $net = (float) $orderItem->unit_price * (float) $orderItem->qty
            - (float) $orderItem->discount_amount
            + (float) $orderItem->tax_amount;

        return (int) round(max(0.0, $net) * 100);
    }

    private function syncOrderPaymentStatusAfterRefund(Order $order): void
    {
        if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return;
        }
        $refundedCents = (int) round((float) $order->returnRequests()->where('status', ReturnStatus::Refunded)->sum('total_refund_amount') * 100);
        $refundableCents = (int) $order->items()->get()->sum(fn (OrderItem $item): int => $this->netLineRefundCents($item));
        if ($refundableCents > 0 && $refundedCents >= $refundableCents) {
            $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
        } elseif ($refundedCents > 0) {
            $order->update(['payment_status' => 'partially_refunded']);
        }
    }

    /**
     * حفظ صور العيوب على القرص الخاص داخل معاملة إنشاء المرتجع،
     * وتسجيل مساراتها في attachments البند.
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
                $paths[] = $files->store($file, "return_requests/{$return->id}");
            }
            $item->update(['attachments' => array_merge($item->attachments ?? [], $paths)]);
        }
    }

    /** هل اكتمل استلام ورد قيمة كل بنود الطلب عبر المرتجعات؟ */
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
