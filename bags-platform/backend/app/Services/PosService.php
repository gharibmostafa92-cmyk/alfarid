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

            // تسجيل رصيد الفتح في دفتر الخزينة
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
            $customer    = isset($payload['customer_id'])
                ? Customer::find($payload['customer_id'])
                : null;

            $overridePrice = (bool) ($payload['override_price'] ?? false);
            $items         = $this->resolveItems($shift, $payload['items'], $customer, $overridePrice);

            $subtotal      = $items->sum('line_total');
            $discountTotal = $items->sum('discount_amount');
            $grandTotal    = $subtotal - $discountTotal;

            // التحقق من الدفع المختلط
            $cashAmount = (float) ($payload['cash_amount'] ?? $grandTotal);
            $cardAmount = (float) ($payload['card_amount'] ?? 0);

            if (abs(($cashAmount + $cardAmount) - $grandTotal) > 0.01) {
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

                // خصم فوري من on_hand
                $this->inventory->recordMovement([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id'          => $shift->branch_id,
                    'type'               => StockMovementType::Sale,
                    'affected_bucket'    => StockBucket::OnHand,
                    'quantity'           => -$item['qty'],
                    'reference_type'     => Order::class,
                    'reference_id'       => $order->id,
                    'created_by'         => $shift->user_id,
                    'notes'              => "بيع POS #{$orderNumber}",
                ]);
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
            $returnValue   = 0.0;
            $returnOrderId = null;

            // ===== معالجة الأصناف المرتجعة =====
            if (! empty($returnedItems)) {
                foreach ($returnedItems as $ret) {
                    $originalItem = $originalOrder->items
                        ->firstWhere('product_variant_id', $ret['product_variant_id']);

                    if ($originalItem === null) {
                        throw new BusinessException("الصنف {$ret['product_variant_id']} غير موجود في الطلب الأصلي.");
                    }

                    if ($ret['qty'] > $originalItem->qty) {
                        throw new BusinessException("كمية المرتجع ({$ret['qty']}) أكبر من الكمية المباعة ({$originalItem->qty}).");
                    }

                    $returnValue += (float) $originalItem->unit_price * $ret['qty'];
                    $bucket = ($ret['return_to'] ?? 'on_hand') === 'damaged'
                        ? StockBucket::Damaged
                        : StockBucket::OnHand;

                    // إعادة الكمية للمخزون
                    $this->inventory->recordMovement([
                        'product_variant_id' => $ret['product_variant_id'],
                        'branch_id'          => $shift->branch_id,
                        'type'               => StockMovementType::SaleReturn,
                        'affected_bucket'    => $bucket,
                        'quantity'           => $ret['qty'],
                        'reference_type'     => Order::class,
                        'reference_id'       => $originalOrder->id,
                        'created_by'         => $shift->user_id,
                        'notes'              => "مرتجع POS من #{$originalOrder->order_number}",
                    ]);
                }

                // إنشاء طلب مرتجع
                $returnOrderNumber = $this->settings->generateNextNumber('order');
                $returnOrder       = Order::create([
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
                    'cash_amount'         => $returnValue, // يُرد نقدًا من الدرج
                    'card_amount'         => 0,
                ]);

                foreach ($returnedItems as $ret) {
                    $originalItem = $originalOrder->items->firstWhere('product_variant_id', $ret['product_variant_id']);
                    OrderItem::create([
                        'order_id'              => $returnOrder->id,
                        'product_variant_id'    => $ret['product_variant_id'],
                        'product_name_snapshot' => $originalItem->product_name_snapshot,
                        'sku_snapshot'          => $originalItem->sku_snapshot,
                        'allocated_branch_id'   => $shift->branch_id,
                        'qty'                   => $ret['qty'],
                        'unit_price'            => $originalItem->unit_price,
                        'discount_amount'       => 0,
                        'line_total'            => (float) $originalItem->unit_price * $ret['qty'],
                    ]);
                }

                OrderStatusHistory::create([
                    'order_id'        => $returnOrder->id,
                    'from_status'     => null,
                    'to_status'       => OrderStatus::Returned,
                    'changed_by_type' => User::class,
                    'changed_by_id'   => $shift->user_id,
                    'notes'           => "مرتجع من #{$originalOrder->order_number}",
                ]);

                // رد النقدية للعميل من الدرج
                $this->treasury->record([
                    'treasury_id'    => $shift->treasury_id,
                    'shift_id'       => $shift->id,
                    'type'           => TreasuryTransactionType::PosReturn,
                    'amount'         => -$returnValue,
                    'reference_type' => Order::class,
                    'reference_id'   => $returnOrder->id,
                    'created_by'     => $shift->user_id,
                    'notes'          => "رد مرتجع #{$returnOrderNumber}",
                ]);

                $returnOrderId = $returnOrder->id;
            }

            // ===== معالجة الاستبدال =====
            $exchangeOrderId = null;
            if (! empty($exchangeItems)) {
                $exchangeOrder = $this->createSale($shift, [
                    'items'       => $exchangeItems,
                    'customer_id' => $originalOrder->customer_id,
                    'cash_amount' => max(0, 0 - $returnValue), // صافي الفرق
                    'card_amount' => 0,
                ]);
                $exchangeOrderId = $exchangeOrder->id;
            }

            return [
                'return_order_id'   => $returnOrderId,
                'exchange_order_id' => $exchangeOrderId,
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
                ->whereNull('location_id')
                ->first();

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
                'on_hand'           => (float) ($stock?->on_hand ?? 0),
                'available'         => (float) ($stock?->available ?? 0),
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
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()
                ->where('id', $item['product_variant_id'])
                ->where('is_active', true)
                ->firstOrFail();

            // التحقق من المخزون المتاح في الفرع
            $stock = StockLevel::query()
                ->where('product_variant_id', $variant->id)
                ->where('branch_id', $shift->branch_id)
                ->whereNull('location_id')
                ->first();

            $available = (float) ($stock?->available ?? 0);

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

            $discount  = (float) ($item['discount_amount'] ?? 0);
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
}
