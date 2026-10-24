<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PosShiftStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TreasuryTransactionType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * محرك نقطة البيع — كل عملية POS تمر من هنا.
 *
 * القاعدة الذهبية: الكاشير لا يُنفذ أي عملية بلا وردية مفتوحة في فرعه.
 * البيع يخصم من on_hand فوراً (لا حجز مؤقت كالأونلاين).
 */
class PosService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PricingService $pricing,
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
    ) {}

    // ===================================================================
    //  الورديات
    // ===================================================================

    /**
     * فتح وردية جديدة للكاشير.
     */
    public function openShift(User $user, int $branchId, int $treasuryId, float $openingCash): PosShift
    {
        return DB::transaction(function () use ($user, $branchId, $treasuryId, $openingCash): PosShift {
            app(BranchAccess::class)->assertBranch($user->id, $branchId);
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! is_finite($openingCash) || $openingCash < 0) throw new BusinessException('العهدة النقدية غير صالحة.');
            // التحقق من عدم وجود وردية مفتوحة لنفس الكاشير
            $existingUserShift = PosShift::query()
                ->where('user_id', $user->id)
                ->where('status', PosShiftStatus::Open)
                ->lockForUpdate()
                ->exists();

            if ($existingUserShift) {
                throw new BusinessException('لديك وردية مفتوحة بالفعل. أغلقها أولًا قبل فتح وردية جديدة.');
            }

            // التحقق من عدم وجود وردية مفتوحة على نفس الدرج
            $existingDrawerShift = PosShift::query()
                ->where('treasury_id', $treasuryId)
                ->where('status', PosShiftStatus::Open)
                ->lockForUpdate()
                ->exists();

            if ($existingDrawerShift) {
                throw new BusinessException('درج النقدية المحدد مفتوح على وردية أخرى.');
            }

            // التحقق أن الخزينة تتبع الفرع وهي من نوع درج
            $treasury = Treasury::query()
                ->where('id', $treasuryId)
                ->where('branch_id', $branchId)
                ->where('type', 'pos_drawer')
                ->where('is_active', true)
                ->firstOrFail();

            $mainSafe = Treasury::query()->where('branch_id', $branchId)->where('type', 'main_safe')->where('is_active', true)->orderBy('id')->first();
            $treasuries = $this->treasury->lockTreasuries(array_filter([$treasury->id, $mainSafe?->id]));
            $treasury = $treasuries->get($treasury->id);
            if (PosShift::query()->where('treasury_id', $treasuryId)->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])->exists()) {
                throw new BusinessException('الدرج مرتبط بوردية مفتوحة أو تنتظر الاعتماد.');
            }
            $existingCash = $this->minor($treasury->current_balance);
            $openingCents = $this->minor($openingCash);
            if ($existingCash !== 0 && $existingCash !== $openingCents) {
                throw new BusinessException('العهدة لا تطابق رصيد الدرج القائم؛ سوِّ الرصيد قبل فتح الوردية.');
            }
            if ($existingCash === 0 && $openingCents > 0 && $mainSafe === null) {
                throw new BusinessException('يلزم وجود خزنة رئيسية لتمويل عهدة الوردية.');
            }

            $shiftNumber = $this->settings->generateNextNumber('shift');

            $shift = PosShift::create([
                'shift_number'          => $shiftNumber,
                'user_id'               => $user->id,
                'branch_id'             => $branchId,
                'treasury_id'           => $treasuryId,
                'status'                => PosShiftStatus::Open,
                'opened_at'             => now(),
                'opening_cash'          => $openingCash,
                'cash_sales_total'      => 0,
                'card_sales_total'      => 0,
                'cash_returns_total'    => 0,
                'cash_expenses_total'   => 0,
                'expected_closing_cash' => $openingCash,
            ]);

            // Fund the float from an existing safe; never mint new drawer cash.
            if ($existingCash === 0 && $openingCents > 0) {
                $this->treasury->record([
                    'treasury_id' => $mainSafe->id, 'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::TransferOut, 'amount' => -$openingCash,
                    'reference_type' => PosShift::class, 'reference_id' => $shift->id,
                    'created_by' => $user->id, 'notes' => 'تمويل عهدة الوردية '.$shiftNumber,
                ]);
                $this->treasury->record([
                'treasury_id'    => $treasury->id,
                'shift_id'       => $shift->id,
                'type'           => TreasuryTransactionType::OpeningBalance,
                'amount'         => $openingCash,
                'reference_type' => PosShift::class,
                'reference_id'   => $shift->id,
                'created_by'     => $user->id,
                'notes'          => "رصيد فتح وردية #{$shiftNumber}",
            ]);
            }

            return $shift;
        });
    }

    /**
     * إغلاق الوردية بعد عدّ الكاشير للنقدية.
     *
     * expected_closing_cash = opening_cash + cash_sales - cash_returns - cash_expenses
     * cash_difference = actual - expected
     */
    public function closeShift(PosShift $shift, float $actualCash, ?string $notes): PosShift
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('يمكن إغلاق الورديات المفتوحة فقط.');
        }

        return DB::transaction(function () use ($shift, $actualCash, $notes): PosShift {
            $shift = $this->lockOpenShift($shift);
            if (! is_finite($actualCash) || $actualCash < 0) throw new BusinessException('النقدية الفعلية غير صالحة.');
            // إعادة حساب مجاميع الوردية من بيانات الطلبات الفعلية
            $salesStats = Order::query()
                ->where('pos_shift_id', $shift->id)
                ->where('status', OrderStatus::Delivered)
                ->selectRaw('
                    COALESCE(SUM(cash_amount), 0) AS cash_sales,
                    COALESCE(SUM(card_amount), 0) AS card_sales
                ')
                ->first();

            $cashSales = (float) ($salesStats?->cash_sales ?? 0);
            $cardSales = (float) ($salesStats?->card_sales ?? 0);

            $cashReturns = (float) Order::query()
                ->where('pos_shift_id', $shift->id)
                ->where('status', OrderStatus::Returned)
                ->sum('cash_amount');

            $cashExpenses = (float) Expense::query()
                ->where('shift_id', $shift->id)
                ->sum('amount');

            $expectedClosingCash = (float) $shift->opening_cash
                + $cashSales
                - $cashReturns
                - $cashExpenses;

            $cashDifference = $actualCash - $expectedClosingCash;

            $shift->update([
                'status'                => PosShiftStatus::ClosedPendingApproval,
                'closed_at'             => now(),
                'cash_sales_total'      => $cashSales,
                'card_sales_total'      => $cardSales,
                'cash_returns_total'    => $cashReturns,
                'cash_expenses_total'   => $cashExpenses,
                'expected_closing_cash' => $expectedClosingCash,
                'actual_closing_cash'   => $actualCash,
                'cash_difference'       => $cashDifference,
                'notes'                 => $notes,
            ]);

            return $shift->fresh();
        });
    }

    /**
     * الاعتماد الإداري للوردية المغلقة وترحيل النقدية للخزنة الرئيسية.
     */
    public function approveShift(PosShift $shift, User $approver): PosShift
    {
        if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
            throw new BusinessException('يمكن اعتماد الورديات المغلقة المنتظرة للاعتماد فقط.');
        }

        return DB::transaction(function () use ($shift, $approver): PosShift {
            $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            app(BranchAccess::class)->assertBranch($approver->id, $shift->branch_id);
            if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
                throw new BusinessException('الوردية ليست في انتظار الاعتماد.');
            }
            $shift->update([
                'status'      => PosShiftStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $approver->id,
            ]);

            // ترحيل النقدية من الدرج إلى الخزنة الرئيسية
            $this->treasury->settleShiftToMainSafe($shift, $approver->id);

            return $shift->fresh();
        });
    }

    // ===================================================================
    //  البيع
    // ===================================================================

    /**
     * إنشاء فاتورة بيع POS مع الخصم الفوري من المخزن.
     *
     * @param  array{
     *     items: array<array{product_variant_id: int, qty: int, unit_price?: float|null, discount_amount?: float}>,
     *     customer_id?: int|null,
     *     payment_method?: string,
     *     cash_amount?: float,
     *     card_amount?: float,
     *     notes?: string|null,
     *     override_price?: bool,
     * }  $payload
     */
    public function createSale(PosShift $shift, array $payload): Order
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي بيع.');
        }

        return DB::transaction(function () use ($shift, $payload): Order {
            $shift = $this->lockOpenShift($shift);
            $actor = User::findOrFail($shift->user_id);
            if (! empty($payload['override_price']) && ! $actor->can('pos.override_price')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('لا تملك صلاحية تجاوز سعر البيع.');
            }
            if (collect($payload['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $actor->can('pos.discount')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('لا تملك صلاحية تطبيق خصم يدوي.');
            }
            $stockRows = StockLevel::query()->where('branch_id', $shift->branch_id)
                ->whereIn('product_variant_id', array_column($payload['items'], 'product_variant_id'))
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get(['branch_id', 'location_id', 'product_variant_id']);
            $levels = $this->inventory->lockLevels($stockRows->map->getAttributes()->all());
            $customer    = isset($payload['customer_id'])
                ? Customer::find($payload['customer_id'])
                : null;

            $overridePrice = (bool) ($payload['override_price'] ?? false);
            $items         = $this->resolveItems($shift, $payload['items'], $customer, $overridePrice);

            // C1: subtotal إجمالي قبل الخصم، والإجمالي هو مجموع line_total (الصافي).
            // line_total محسوب في resolveItems كـ round(unit*qty - discount).
            $subtotal = $items->sum(fn ($item) => $this->minor($item['unit_price']) * (int) $item['qty']) / 100;
            $discountTotal = $items->sum(fn ($item) => $this->minor($item['discount_amount'])) / 100;
            $grandTotal = $items->sum(fn ($item) => $this->minor($item['line_total'])) / 100;

            if ($grandTotal < 0) {
                throw new BusinessException('إجمالي الفاتورة لا يمكن أن يكون سالبًا.');
            }

            // التحقق من الدفع المختلط — M10: لا مبالغ سالبة، ومطابقة بالقروش الصحيحة
            $cashAmount = (float) ($payload['cash_amount'] ?? $grandTotal);
            $cardAmount = (float) ($payload['card_amount'] ?? 0);

            if ($cashAmount < 0 || $cardAmount < 0) {
                throw new BusinessException('مبالغ الدفع يجب ألا تكون سالبة.');
            }

            $grandCents = $this->minor($grandTotal);
            $paidCents = $this->minor($cashAmount) + $this->minor($cardAmount);

            if ($paidCents !== $grandCents) {
                throw new BusinessException('مجموع المبالغ (نقدي + بطاقة) لا يساوي إجمالي الفاتورة.');
            }

            // توليد رقم الطلب بنفس آلية الـ order_next_number
            $orderNumber = $this->settings->generateNextNumber('order');

            // إنشاء الطلب بحالة delivered مباشرة (POS = تسليم فوري)
            $order = Order::create([
                'order_number'        => $orderNumber,
                'channel'             => 'pos',
                'customer_id'         => $customer?->id,
                'allocated_branch_id' => $shift->branch_id,
                'pos_shift_id'        => $shift->id,
                'status'              => OrderStatus::Delivered,
                'payment_method'      => $payload['payment_method'] ?? 'mixed',
                'payment_status'      => 'paid',
                'subtotal'            => $subtotal,
                'discount_amount'     => $discountTotal,
                'shipping_cost'       => 0,
                'tax_amount'          => 0,
                'grand_total'         => $grandTotal,
                'cash_amount'         => $cashAmount,
                'card_amount'         => $cardAmount,
            ]);

            // إنشاء عناصر الطلب وخصم المخزون فوراً
            foreach ($items as $item) {
                OrderItem::create([
                    'order_id'               => $order->id,
                    'product_variant_id'     => $item['product_variant_id'],
                    'product_name_snapshot'  => $item['product_name_snapshot'],
                    'sku_snapshot'           => $item['sku_snapshot'],
                    'allocated_branch_id'    => $shift->branch_id,
                    'qty'                    => $item['qty'],
                    'unit_price'             => $item['unit_price'],
                    'discount_amount'        => $item['discount_amount'],
                    'line_total'             => $item['line_total'],
                ]);

                $remaining = (int) round((float) $item['qty'] * 10000);
                foreach ($levels->where('product_variant_id', $item['product_variant_id']) as $level) {
                    $take = min($remaining, (int) round($level->available * 10000));
                    if ($take <= 0) continue;
                    $this->inventory->recordMovement([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id'          => $shift->branch_id,
                    'location_id' => $level->location_id,
                    'type'               => StockMovementType::Sale,
                    'affected_bucket'    => StockBucket::OnHand,
                    'quantity' => -$take / 10000,
                    'unit_cost' => $item['unit_cost'],
                    'reference_type'     => Order::class,
                    'reference_id'       => $order->id,
                    'created_by'         => $shift->user_id,
                    'notes'              => "بيع POS #{$orderNumber}",
                    ], $level);
                    $remaining -= $take;
                }
                if ($remaining > 0) throw new BusinessException('الرصيد المتاح تغير؛ أعد مراجعة الكميات.');
            }

            // تسجيل تاريخ الحالة
            OrderStatusHistory::create([
                'order_id'        => $order->id,
                'from_status'     => null,
                'to_status'       => OrderStatus::Delivered,
                'changed_by_type' => User::class,
                'changed_by_id'   => $shift->user_id,
                'notes'           => 'بيع POS مباشر',
            ]);

            // تسجيل المقبوضة النقدية في الخزينة
            if ($cashAmount > 0) {
                $this->treasury->record([
                    'treasury_id'    => $shift->treasury_id,
                    'shift_id'       => $shift->id,
                    'type'           => TreasuryTransactionType::PosSale,
                    'amount'         => $cashAmount,
                    'reference_type' => Order::class,
                    'reference_id'   => $order->id,
                    'created_by'     => $shift->user_id,
                    'notes'          => "مبيعات نقدية POS #{$orderNumber}",
                ]);
            }

            return $order->load(['items.variant', 'customer']);
        });
    }

    /**
     * مرتجع كلي أو جزئي من فاتورة POS.
     * يُعيد البضاعة إلى on_hand ويرد النقدية من الدرج إن كانت مدفوعة نقداً.
     *
     * @param  array<array{product_variant_id: int, qty: int, return_to?: string}>  $returnedItems
     * @param  array<array{product_variant_id: int, qty: int}>  $exchangeItems (للاستبدال)
     */
    public function processReturn(
        PosShift $shift,
        Order $originalOrder,
        array $returnedItems,
        array $exchangeItems = []
    ): array {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي مرتجع.');
        }

        if ($originalOrder->channel !== 'pos') {
            throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
        }

        return DB::transaction(function () use ($shift, $originalOrder, $returnedItems, $exchangeItems): array {
            $shift = $this->lockOpenShift($shift);
            // C2: قفل الطلب الأصلي أولًا حتى تتسلسل المرتجعات المتزامنة عليه.
            /** @var Order $originalOrder */
            $originalOrder = Order::query()->lockForUpdate()->findOrFail($originalOrder->id);
            $originalOrder->load('items');
            app(BranchAccess::class)->assertBranch($shift->user_id, (int) $originalOrder->allocated_branch_id);
            if ($originalOrder->channel !== 'pos') {
                throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
            }

            if ($originalOrder->status !== OrderStatus::Delivered) {
                throw new BusinessException('المرتجع متاح لطلبات POS المسلمة فقط.');
            }

            // C2: طلبات المرتجع نفسها حالتها Returned — منع "إرجاع المرتجع".
            // (الحالة Delivered إلزامية أعلاه، وهذا تأكيد دفاعي + عمود الربط بعد المايجريشن.)
            if ($originalOrder->getAttribute('return_of_order_id') !== null) {
                throw new BusinessException('لا يمكن إرجاع طلب مرتجع.');
            }

            // C2: إزالة مسار الاستبدال المحسوب داخليًا (كان يفشل دائمًا).
            // الاستبدال عملية بيع مستقلة يرسلها العميل بمبالغها.
            if (! empty($exchangeItems)) {
                throw new BusinessException('الاستبدال يتم كعملية بيع مستقلة بعد المرتجع، وليس ضمن نفس الطلب.');
            }

            $returnValue   = 0.0;
            $returnOrderId = null;

            // ===== معالجة الأصناف المرتجعة =====
            if (! empty($returnedItems)) {
                // C2: تجميع الأسطر المكررة لنفس المتغير قبل أي فحص.
                $grouped = [];
                foreach ($returnedItems as $ret) {
                    $vid = (int) $ret['product_variant_id'];
                    $qty = (float) $ret['qty'];
                    if (! is_finite($qty) || $qty <= 0 || floor($qty) !== $qty) {
                        throw new BusinessException('كمية المرتجع يجب أن تكون موجبة.');
                    }
                    $destination = $ret['return_to'] ?? 'on_hand';
                    if (! in_array($destination, ['on_hand', 'damaged'], true)
                        || (isset($grouped[$vid]) && $grouped[$vid]['return_to'] !== $destination)) {
                        throw new BusinessException('حدد حالة واحدة صحيحة لكل صنف مرتجع.');
                    }
                    if (! isset($grouped[$vid])) {
                        $grouped[$vid] = ['qty' => 0.0, 'return_to' => $ret['return_to'] ?? 'on_hand'];
                    }
                    $grouped[$vid]['qty'] += $qty;
                }

                $lines = [];
                foreach ($grouped as $variantId => $g) {
                    // C2: كل سطور الطلب الأصلي لنفس المتغير (وليس firstWhere فقط).
                    $matching = $originalOrder->items->where('product_variant_id', $variantId);
                    if ($matching->isEmpty()) {
                        throw new BusinessException("الصنف {$variantId} غير موجود في الطلب الأصلي.");
                    }

                    $soldQty = (float) $matching->sum('qty');
                    $soldNet = round((float) $matching->sum('line_total'), 2);
                    $netUnit = $soldQty > 0 ? $soldNet / $soldQty : 0.0;

                    // C2: الكميات المرتجعة سابقًا من دفتر الحركات (مصدر الحقيقة قبل مايجريشن returned_qty).
                    $alreadyReturned = (float) StockMovement::query()
                        ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                        ->where('reference_id', $originalOrder->id)
                        ->where('type', StockMovementType::SaleReturn)
                        ->where('product_variant_id', $variantId)
                        ->sum('quantity');

                    $remaining = $soldQty - $alreadyReturned;
                    if ($g['qty'] > $remaining + 1e-9) {
                        throw new BusinessException("كمية المرتجع ({$g['qty']}) تتجاوز المتبقي القابل للإرجاع ({$remaining}).");
                    }

                    // C2: الرد بالسعر الصافي (line_total/qty) لا بسعر الوحدة قبل الخصم.
                    $previousRefund = OrderItem::query()->where('product_variant_id', $variantId)
                        ->whereHas('order', fn ($q) => $q->withTrashed()->where('return_of_order_id', $originalOrder->id))
                        ->sum('line_total');
                    $targetCents = (int) round(($alreadyReturned + $g['qty']) * $this->minor($soldNet) / $soldQty);
                    $lineRefund = max(0, min($this->minor($soldNet), $targetCents) - $this->minor($previousRefund)) / 100;
                    $returnValue = round($returnValue + $lineRefund, 2);

                    $first = $matching->first();
                    $lines[$variantId] = [
                        'qty' => $g['qty'],
                        'return_to' => $g['return_to'],
                        'net_unit' => $netUnit,
                        'line_refund' => $lineRefund,
                        'product_name_snapshot' => $first->product_name_snapshot,
                        'sku_snapshot' => $first->sku_snapshot,
                    ];
                }

                if ($returnValue < 0) {
                    throw new BusinessException('قيمة المرتجع لا يمكن أن تكون سالبة.');
                }

                // C2: سقف الرد النقدي = النقد المدفوع في الأصل ناقص ما رُد سابقًا.
                $previousCashRefunded = 0.0;
                    $previousCashRefunded = (float) Order::withTrashed()
                        ->where('return_of_order_id', $originalOrder->id)
                        ->sum('cash_amount');
                $maxCashRefund = round((float) $originalOrder->cash_amount - $previousCashRefunded, 2);
                $cashRefund = min($returnValue, max(0.0, $maxCashRefund));

                $locationId = $this->inventory->receivingLocation((int) $shift->branch_id);
                $levels = $this->inventory->lockLevels(array_map(fn ($variantId) => [
                    'product_variant_id' => $variantId, 'branch_id' => $shift->branch_id, 'location_id' => $locationId,
                ], array_keys($lines)));

                foreach ($lines as $variantId => $line) {
                    $base = [
                        'product_variant_id' => $variantId,
                        'branch_id'          => $shift->branch_id,
                        'location_id' => $locationId,
                        'type'               => StockMovementType::SaleReturn,
                        'affected_bucket'    => StockBucket::OnHand,
                        'quantity'           => $line['qty'],
                        'reference_type'     => Order::class,
                        'reference_id'       => $originalOrder->id,
                        'created_by'         => $shift->user_id,
                        'notes'              => "مرتجع POS من #{$originalOrder->order_number}",
                    ];
                    $level = $levels->get($this->inventory->levelKey($base));
                    $saleCost = StockMovement::query()->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                        ->where('reference_id', $originalOrder->id)->where('product_variant_id', $variantId)->where('type', 'sale')->value('unit_cost');
                    $this->inventory->recordMovement([...$base, 'unit_cost' => $saleCost], $level);
                    if ($line['return_to'] === 'damaged') {
                        $this->inventory->recordMovement([...$base, 'type' => StockMovementType::Damage, 'affected_bucket' => StockBucket::Damaged], $level);
                    }
                }

                // إنشاء طلب مرتجع مرتبط بالأصل
                $returnOrderNumber = $this->settings->generateNextNumber('order');
                $returnAttrs       = [
                    'order_number'        => $returnOrderNumber,
                    'channel'             => 'pos',
                    'customer_id'         => $originalOrder->customer_id,
                    'allocated_branch_id' => $shift->branch_id,
                    'pos_shift_id'        => $shift->id,
                    'status'              => OrderStatus::Returned,
                    'payment_method'      => $originalOrder->payment_method,
                    'payment_status'      => 'refunded',
                    'subtotal'            => $returnValue,
                    'discount_amount'     => 0,
                    'shipping_cost'       => 0,
                    'tax_amount'          => 0,
                    'grand_total'         => $returnValue,
                    'cash_amount'         => $cashRefund,
                    'card_amount'         => round($returnValue - $cashRefund, 2),
                ];
                $returnAttrs['return_of_order_id'] = $originalOrder->id;
                $returnAttrs['requires_refund'] = $returnAttrs['card_amount'] > 0;
                $returnAttrs['payment_status'] = $returnAttrs['card_amount'] > 0 ? 'refund_pending' : 'refunded';
                $returnOrder = Order::create($returnAttrs);

                foreach ($lines as $variantId => $line) {
                    OrderItem::create([
                        'order_id'              => $returnOrder->id,
                        'product_variant_id'    => $variantId,
                        'product_name_snapshot' => $line['product_name_snapshot'],
                        'sku_snapshot'          => $line['sku_snapshot'],
                        'allocated_branch_id'   => $shift->branch_id,
                        'qty'                   => $line['qty'],
                        'unit_price'            => round($line['net_unit'], 2),
                        'discount_amount'       => 0,
                        'line_total'            => $line['line_refund'],
                    ]);
                }

                foreach ($grouped as $variantId => $g) {
                    // Reconcile counters from the ledger as well as this return, including legacy returns.
                    $remaining = (float) StockMovement::query()
                        ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                        ->where('reference_id', $originalOrder->id)->where('type', StockMovementType::SaleReturn)
                        ->where('product_variant_id', $variantId)->sum('quantity');
                    foreach ($originalOrder->items->where('product_variant_id', $variantId)->sortBy('id') as $item) {
                        $take = min($remaining, (float) $item->qty);
                        $item->update(['returned_qty' => $take]);
                        $remaining -= $take;
                    }
                }

                OrderStatusHistory::create([
                    'order_id'        => $returnOrder->id,
                    'from_status'     => null,
                    'to_status'       => OrderStatus::Returned,
                    'changed_by_type' => User::class,
                    'changed_by_id'   => $shift->user_id,
                    'notes'           => "مرتجع من #{$originalOrder->order_number}",
                ]);

                // رد النقدية للعميل من الدرج (مكبوتة بسقف النقد المدفوع)
                if ($cashRefund > 0) {
                    $this->treasury->record([
                        'treasury_id'    => $shift->treasury_id,
                        'shift_id'       => $shift->id,
                        'type'           => TreasuryTransactionType::PosReturn,
                        'amount'         => -$cashRefund,
                        'reference_type' => Order::class,
                        'reference_id'   => $returnOrder->id,
                        'created_by'     => $shift->user_id,
                        'notes'          => "رد مرتجع #{$returnOrderNumber}",
                    ]);
                }

                $returnOrderId = $returnOrder->id;
            }

            return [
                'return_order_id'   => $returnOrderId,
                'exchange_order_id' => null,
                'refund_amount'     => $returnValue,
            ];
        });
    }

    // ===================================================================
    //  الفواتير المعلقة (Hold)
    // ===================================================================

    public function holdSale(PosShift $shift, array $payload): PosHeldSale
    {
        return PosHeldSale::create([
            'hold_reference' => 'HOLD-'.strtoupper(Str::random(8)),
            'branch_id'      => $shift->branch_id,
            'user_id'        => $shift->user_id,
            'customer_id'    => $payload['customer_id'] ?? null,
            'items_payload'  => $payload['items'],
            'notes'          => $payload['notes'] ?? null,
            'created_at'     => now(),
        ]);
    }

    // ===================================================================
    //  البحث السريع عن المنتجات
    // ===================================================================

    /**
     * بحث فائق السرعة بالباركود أو SKU أو جزء من الاسم.
     * يرجع المتغير مع رصيده في الفرع الحالي فقط وسعره.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lookupProducts(string $query, int $branchId, ?Customer $customer = null): array
    {
        $variants = ProductVariant::query()
            ->with(['product', 'media'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereNotNull('published_at'))
            ->where('is_active', true)
            ->where(function ($q) use ($query): void {
                $q->where('barcode', $query)
                    ->orWhere('sku', $query)
                    ->orWhereHas('product', fn ($pq) => $pq->whereRaw('LOWER(name_ar) LIKE ? OR LOWER(name_en) LIKE ?', ['%'.mb_strtolower($query).'%', '%'.mb_strtolower($query).'%']));
            })
            ->limit(20)
            ->get();

        $result = [];
        foreach ($variants as $variant) {
            $stock = StockLevel::query()
                ->where('product_variant_id', $variant->id)
                ->where('branch_id', $branchId)
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get();

            try {
                $priceData = $this->pricing->resolve($variant, $customer, 1, $branchId);
            } catch (\Throwable) {
                $priceData = ['unit_price' => null, 'source' => 'unpriced', 'price_list_id' => null];
            }

            $result[] = [
                'id'                => $variant->id,
                'sku'               => $variant->sku,
                'barcode'           => $variant->barcode,
                'product_name'      => $variant->product->name_ar ?? $variant->product->name_en,
                'variant_name'      => $variant->color_name_ar ?? $variant->color_name_en,
                'on_hand' => (float) $stock->sum('on_hand'),
                'available' => (float) $stock->sum('available'),
                'unit_price'        => $priceData['unit_price'],
                'price_source'      => $priceData['source'],
                'price_list_id'     => $priceData['price_list_id'],
            ];
        }

        return $result;
    }

    // ===================================================================
    //  المساعدات الداخلية
    // ===================================================================

    /**
     * تجهيز عناصر الفاتورة مع حل الأسعار.
     *
     * @param  array<array{product_variant_id: int, qty: int, unit_price?: float|null, discount_amount?: float}>  $rawItems
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveItems(
        PosShift $shift,
        array $rawItems,
        ?Customer $customer,
        bool $overridePrice
    ): Collection {
        return collect($rawItems)->map(function (array $item) use ($shift, $customer, $overridePrice): array {
            $qty = (float) $item['qty'];
            if (! is_finite($qty) || $qty <= 0 || floor($qty) !== $qty) {
                throw new BusinessException('كمية البيع يجب أن تكون عددًا صحيحًا موجبًا.');
            }
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()
                ->where('id', $item['product_variant_id'])
                ->where('is_active', true)
                ->firstOrFail();

            // التحقق من المخزون المتاح في الفرع
            $stock = StockLevel::query()
                ->where('product_variant_id', $variant->id)
                ->where('branch_id', $shift->branch_id)
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get();

            $available = (float) $stock->sum('available');

            if ($available < $item['qty']) {
                throw new BusinessException(
                    "الكمية المتاحة للصنف [{$variant->sku}] في الفرع هي {$available} فقط.",
                    status: 422,
                );
            }

            // السعر: يدوي (بشرط الصلاحية) أو محسوب
            if ($overridePrice && isset($item['unit_price'])) {
                $unitPrice = (float) $item['unit_price'];
            } else {
                $priceData = $this->pricing->resolve($variant, $customer, (float) $item['qty'], $shift->branch_id);
                $unitPrice = (float) $priceData['unit_price'];
            }

            if (! is_finite($unitPrice) || $unitPrice < 0) {
                throw new BusinessException("سعر الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            $discount  = (float) ($item['discount_amount'] ?? 0);
            $maxDiscount = (float) config('inventory.pos_max_discount_percent', 100);
            if ($discount > round($unitPrice * (float) $item['qty'] * $maxDiscount / 100, 2)) {
                throw new BusinessException('الخصم يتجاوز النسبة القصوى المسموحة.');
            }
            if (! is_finite($discount) || $discount < 0) {
                throw new BusinessException("خصم الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }
            $lineTotal = round($unitPrice * $item['qty'] - $discount, 2);

            if ($lineTotal < 0) {
                throw new BusinessException("إجمالي السطر للصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            return [
                'product_variant_id'    => $variant->id,
                'product_name_snapshot' => $variant->product->name_ar ?? $variant->product->name_en ?? 'POS Item',
                'sku_snapshot'          => $variant->sku,
                'qty'                   => $item['qty'],
                'unit_price'            => $unitPrice,
                'discount_amount'       => $discount,
                'line_total'            => $lineTotal,
                'unit_cost' => $variant->cost_price,
            ];
        });
    }

    /**
     * تسجيل مصروف من درج الوردية الحالية.
     */
    public function recordExpense(PosShift $shift, array $data, int $userId): Expense
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن تسجيل مصاريف.');
        }

        return DB::transaction(function () use ($shift, $data, $userId): Expense {
            $shift = $this->lockOpenShift($shift);
            $expenseNumber = $this->settings->generateNextNumber('expense');

            $expense = Expense::create([
                'expense_number' => $expenseNumber,
                'branch_id'      => $shift->branch_id,
                'treasury_id'    => $shift->treasury_id,
                'shift_id'       => $shift->id,
                'category'       => $data['category'],
                'amount'         => $data['amount'],
                'expense_date'   => $data['expense_date'] ?? today(),
                'attachment_path'=> $data['attachment_path'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $userId,
            ]);

            // خصم من الخزينة
            $this->treasury->record([
                'treasury_id'    => $shift->treasury_id,
                'shift_id'       => $shift->id,
                'type'           => TreasuryTransactionType::Expense,
                'amount'         => -(float) $data['amount'],
                'reference_type' => Expense::class,
                'reference_id'   => $expense->id,
                'created_by'     => $userId,
                'notes'          => "مصروف: {$data['category']} #{$expenseNumber}",
            ]);

            return $expense;
        });
    }

    private function lockOpenShift(PosShift $shift): PosShift
    {
        $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
        if ($shift->status !== PosShiftStatus::Open) throw new BusinessException('الوردية غير مفتوحة.');

        return $shift;
    }

    private function minor(mixed $value): int
    {
        try {
            return BigDecimal::of((string) $value)->toScale(2, RoundingMode::Unnecessary)->getUnscaledValue()->toInt();
        } catch (\Throwable) {
            throw new BusinessException('المبلغ المالي غير صالح؛ يُسمح بخانتين عشريتين فقط.');
        }
    }
}
