<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\OrderCreated;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * خدمات الطلب.
 * - createFromCart: تحويل سلة إلى طلب بلقطات أسعار من PricingService،
 *   وخصم الكوبون، وشحن المدينة، وضريبة الإعدادات، ثم إفراغ السلة.
 * - changeStatus: بوابة تغيير الحالة الوحيدة — تتحقق من آلة الحالات،
 *   وتسجل التغيير في order_status_histories، وتدير آثار المخزون:
 *   confirmed ← حجز وتوزيع، shipped ← فك حجز + بيع فعلي،
 *   cancelled (من confirmed/processing) ← فك حجز.
 */
class OrderService
{
    /** الانتقالات المسموحة بين حالات الطلب. */
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public function __construct(
        protected OrderAllocationService $allocationService,
        protected CartService $cartService,
        protected CouponService $couponService,
        protected SettingService $settings,
        protected InventoryService $inventoryService,
        protected ShippingQuoteService $shippingQuote,
    ) {}

    /**
     * تحويل سلة إلى طلب جديد بحالة pending.
     *
     * @param  array{recipient_name?: string|null, recipient_phone?: string|null, recipient_email?: string|null, city_id?: int|null, shipping_address_line?: string|null, shipping_notes?: string|null, payment_method?: string|null, channel?: string}  $data
     */
    public function createFromCart(Cart $cart, array $data, ?Customer $customer = null): Order
    {
        // إعادة إرسال بنفس مفتاح منع التكرار ترجع الطلب نفسه (F-13):
        // انقطاع الشبكة بعد إنشاء الطلب لا يعني «سلة فارغة» ولا طلبًا مكررًا.
        // المفتاح مقيّد بهوية المُرسِل (F2): زائر لا يستطيع سرقة طلب غيره أو
        // تعطيله بمعرفة/تخمين مفتاحه، فالمفتاح وحده ملك مشترك للفضاء كله.
        $idempotencyKey = isset($data['idempotency_key']) && is_string($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? mb_substr($data['idempotency_key'], 0, 191)
            : null;

        $guestPhone = $data['recipient_phone'] ?? $customer?->phone;
        $guestPhoneNormalized = is_string($guestPhone) && trim($guestPhone) !== ''
            ? Customer::normalizePhone($guestPhone)
            : null;
        // هاتف زائر لا يحمل أرقامًا بعد التوحيد = هوية مشتركة بين كل من أرسل
        // مدخلًا عشوائيًا (M3): نطاق idempotency يصير 'guest:' عامًا ويتطابق
        // التتبع مع أي هاتف. يُرفض بدل أن يفسد Discounts/التتبع.
        if ($customer === null && $guestPhoneNormalized !== null
            && preg_match('/\d/', $guestPhoneNormalized) !== 1) {
            throw new BusinessException('رقم هاتف صالح مطلوب لإتمام الشراء كزائر.');
        }
        $idempotencyScope = $customer !== null
            ? 'customer:'.$customer->id
            : ($guestPhoneNormalized !== null ? 'guest:'.$guestPhoneNormalized : null);

        if ($idempotencyKey !== null && $idempotencyScope !== null) {
            $existing = Order::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('idempotency_scope', $idempotencyScope)
                ->first();
            if ($existing !== null) {
                return $existing->load('items');
            }
        }

        return DB::transaction(function () use ($cart, $data, $customer, $idempotencyKey, $idempotencyScope, $guestPhoneNormalized): Order {
            // قفل استشاري على (النطاق + المفتاح): طلبان متزامنان بنفس المفتاح
            // يتسلسلان، فيجد الثاني الطلب الأول ويعيده بدل إنشاء ثانٍ (F2/F-13)
            if ($idempotencyKey !== null && $idempotencyScope !== null) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['order-idem:'.$idempotencyScope.':'.$idempotencyKey]);
                $existing = Order::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('idempotency_scope', $idempotencyScope)
                    ->first();
                if ($existing !== null) {
                    return $existing->load('items');
                }
            }

            // قفل صف السلة وقراءة بنودها طازجة: طلب متزامن ثانٍ ينتظر
            // ثم يجد السلة فارغة بدل إنشاء طلب مكرر (H2)
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $cart->load('items');
            if ($cart->items->isEmpty()) {
                throw new BusinessException('السلة فارغة؛ لا يمكن إنشاء طلب.');
            }

            $lines = $this->cartService->pricedLines($cart, $customer);
            foreach ($lines as $line) {
                if ($line['unit_price'] === null) {
                    throw new BusinessException("الصنف ({$line['variant']->sku}) غير مُسعَّر؛ لا يمكن إتمام الطلب.");
                }
                // سطر بقيمة صفر (كمية شبه صفرية × سعر صغير) لا يمر (F-03)
                if ($line['line_total'] <= 0) {
                    throw new BusinessException("قيمة الصنف ({$line['variant']->sku}) صفر؛ راجع الكمية أو السعر.");
                }
            }

            // سقوف المخزون (F5/H2): الزائر بلا تحقق من الهاتف، والعميل المسجّل
            // بلا سقف كانا يحبسان المخزون بكميات ضخمة وطلبات معلّقة. عملاء
            // الجملة/التوزيع المعتمدون مستثنون (مسار الجملة للكميات الكبيرة).
            $isWholesale = $this->isWholesaleApproved($customer);
            if (! $isWholesale) {
                $lineCap = $customer === null
                    ? (float) config('store.guest_max_line_qty', 10)
                    : (float) config('store.retail_max_line_qty', 50);
                if ($lineCap > 0) {
                    foreach ($lines as $line) {
                        if ((float) $line['qty'] > $lineCap) {
                            throw new BusinessException($customer === null
                                ? "سقف كمية البند الواحد للزوار ({$lineCap}) — سجّل الدخول للكميات الأكبر."
                                : "سقف كمية البند الواحد ({$lineCap}) — للكميات الأكبر تواصل مع مسار الجملة.");
                        }
                    }
                }
            }

            if ($customer === null) {
                $pendingCap = (int) config('store.guest_pending_orders_cap', 3);
                if ($pendingCap > 0 && $guestPhoneNormalized !== null) {
                    $pendingForPhone = Order::query()
                        ->whereNull('customer_id')
                        ->where('status', OrderStatus::Pending)
                        ->where('guest_phone_normalized', $guestPhoneNormalized)
                        ->count();
                    if ($pendingForPhone >= $pendingCap) {
                        throw new BusinessException('لديك طلبات معلّقة كثيرة على هذا الرقم؛ أكملها أو ألغِها قبل طلب جديد.');
                    }
                }
            } elseif (! $isWholesale) {
                $pendingCap = (int) config('store.customer_pending_orders_cap', 5);
                if ($pendingCap > 0) {
                    $pendingForCustomer = Order::query()
                        ->where('customer_id', $customer->id)
                        ->where('status', OrderStatus::Pending)
                        ->count();
                    if ($pendingForCustomer >= $pendingCap) {
                        throw new BusinessException('لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.');
                    }
                }
            }

            // إعادة التحقق من التوفر وقابلية البيع لحظة الشراء (M14):
            // صنف عُطّل أو منتج أُلغي نشره/حُذف بعد الإضافة لا يُشترى
            $this->cartService->assertLinesAvailable($lines);
            $this->cartService->assertLinesPurchasable($lines);
            $totals = $this->cartService->totals($cart, $customer, $lines);
            $subtotal = $totals['subtotal'];
            $pricingDiscount = $totals['pricing_discount'];

            // الكوبون: إعادة تحقق صارمة عند الشراء (يشمل حد العميل الواحد)
            $couponDiscount = 0.0;
            $couponId = null;
            $couponCode = null;
            $coupon = null;
            if ($cart->coupon_id !== null) {
                // لا قفل صف كوبون مسبق (F4): ترتيب الأقفال صار مخزون←كوبون،
                // والذرّية تُضمن عند تسجيل الاستخدام (قفل استشاري + زيادة
                // شرطية) فلا جمود بين الشراء والإلغاء
                try {
                    $coupon = $this->couponService->redeemableById(
                        (int) $cart->coupon_id,
                        $totals['items_total'],
                        $customer,
                        $customer === null ? ($data['recipient_phone'] ?? null) : null,
                    );
                } catch (BusinessException $e) {
                    // رمز مميز حتى تعرض الواجهة زر إزالة الكوبون (H8)
                    throw new BusinessException($e->getMessage(), $e->errors, $e->status, 'coupon_invalid');
                }
                // حد معدّل لكل (كوبون + IP) عند إتمام الشراء (H3)
                $this->couponService->enforceRateLimit($coupon, 'redeem');
                $couponDiscount = $this->couponService->discountFor($coupon, $totals['items_total']);
                $couponId = $coupon->id;
                $couponCode = $coupon->code;
            }
            $discountAmount = round($pricingDiscount + $couponDiscount, 2);

            // الشحن من تسعيرة المناطق الموحدة (M12): سعر المنطقة/الشركة
            // وحدها المجاني ورسوم COD — ورجوع لسعر المدينة عند غياب سعر.
            // كوبون الشحن المجاني يعفي من تكلفة الشحن (لا من رسوم COD)
            $city = null;
            $shippingCost = 0.0;
            $codFee = 0.0;
            if (! empty($data['city_id'])) {
                $city = City::query()->where('is_active', true)->find((int) $data['city_id']);
                if ($city === null) {
                    throw new BusinessException('مدينة التوصيل غير متاحة.');
                }
                $quote = $this->shippingQuote->quoteForCity(
                    $city->id,
                    $totals['items_total'] - $couponDiscount,
                    ($data['payment_method'] ?? 'cod') === 'cod',
                );
                $shippingCost = $quote['cost'];
                $codFee = $quote['cod_fee'];
            }
            $waivedShipping = 0.0;
            if ($coupon !== null && $coupon->type === 'free_shipping' && $shippingCost > 0) {
                $waivedShipping = $shippingCost;
                $shippingCost = 0.0;
            }

            // الضريبة من الإعدادات (مفعّلة وغير مشمولة في الأسعار فقط)
            $taxAmount = 0.0;
            $taxRate = 0.0;
            if (filter_var($this->settings->get('tax_enabled', false), FILTER_VALIDATE_BOOLEAN)
                && ! filter_var($this->settings->get('prices_include_tax', false), FILTER_VALIDATE_BOOLEAN)) {
                $taxRate = (float) $this->settings->get('default_tax_rate', 0);
                $taxAmount = round(($subtotal - $discountAmount) * $taxRate / 100, 2);
            }

            $grandTotal = round(($subtotal - $discountAmount) + $shippingCost + $codFee + $taxAmount, 2);

            // حماية من تغيّر السعر بين عرض السلة والشراء (M6): إن أرسل العميل
            // الإجمالي الذي رآه واختلف عمّا حُسب فعليًا نرفض بدل تحصيل زائد صامت
            if (isset($data['expected_total']) && is_numeric($data['expected_total'])
                && abs((float) $data['expected_total'] - $grandTotal) > 0.01) {
                throw new BusinessException(
                    'تغيّر إجمالي الطلب منذ عرضه. راجع السلة ثم أعد المحاولة.',
                    [], 422, 'price_changed',
                );
            }

            $order = Order::create([
                'order_number' => $this->settings->generateNextNumber('order'),
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $idempotencyScope,
                'channel' => $data['channel'] ?? 'store',
                'customer_id' => $customer?->id,
                'guest_name' => $data['recipient_name'] ?? $customer?->name,
                'guest_phone' => $data['recipient_phone'] ?? $customer?->phone,
                'guest_phone_normalized' => $guestPhoneNormalized,
                'guest_email' => $data['recipient_email'] ?? $customer?->email,
                'city_id' => $city?->id,
                'shipping_address_line' => $data['shipping_address_line'] ?? null,
                'shipping_notes' => $data['shipping_notes'] ?? null,
                'status' => OrderStatus::Pending,
                'payment_method' => $data['payment_method'] ?? 'cod',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'shipping_cost' => $shippingCost,
                'cod_fee' => $codFee,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
            ]);

            // بنود الطلب بلقطات الاسم والـ SKU والسعر + توزيع الضريبة وحصة
            // الكوبون على البنود بطريقة «أكبر باقٍ» (L5/M17): الحصة تُحسب
            // بالقروش فتكون الأعداد صحيحة ومجموعها يساوي الإجمالي بالضبط،
            // ولا يقع بند بحصة سالبة بسبب التقريب
            $weights = array_map(fn (array $line): float => (float) $line['line_total'], $lines);
            $taxShares = $this->distributeLargestRemainder($taxAmount, $weights);
            $couponShares = $this->distributeLargestRemainder($couponDiscount, $weights);
            foreach ($lines as $i => $line) {
                $order->items()->create([
                    'product_variant_id' => $line['variant']->id,
                    'product_name_snapshot' => $line['variant']->product?->name_ar ?? '',
                    'sku_snapshot' => $line['variant']->sku,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => round($line['line_discount'] + $couponShares[$i], 2),
                    'tax_amount' => $taxShares[$i],
                    'line_total' => $line['line_total'],
                ]);
            }

            // الحجز الفوري داخل نفس معاملة الشراء (F-01): التوفر يتحول
            // لحجز ذرّي تحت أقفال صفوف الأرصدة بدل انتظار التأكيد، فآخر
            // وحدة لا تُباع مرتين. نقص الرصيد يُسقط الشراء كله هنا.
            $order->load('items');
            $this->allocationService->allocate($order, performedBy: $this->inventoryActorId($customer));
            $order->refresh();

            // تسجيل استخدام الكوبون فعليًا (خصم البضاعة أو الشحن المُعفى).
            // كوبون شحن مجاني وشحن الطلب صفر أصلًا لا يستهلك حدًا بلا فائدة (L4)
            if ($coupon !== null
                && ! ($coupon->type === 'free_shipping' && $waivedShipping <= 0)) {
                $this->couponService->recordUsage(
                    $coupon,
                    $order,
                    $customer,
                    $coupon->type === 'free_shipping' ? $waivedShipping : $couponDiscount,
                );
            }

            if (($data['payment_method'] ?? 'cod') === 'wallet') {
                if ($customer === null || $customer->fresh()->status !== CustomerStatus::Approved) {
                    throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
                }
                app(WalletService::class)->record($customer->id, -$grandTotal, 'checkout_payment', 'order', $order->id);
                $order->update(['payment_status' => 'paid']);
            }

            // أول سجل حالة: إنشاء الطلب
            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'changed_by_type' => $customer?->getMorphClass(),
                'changed_by_id' => $customer?->getKey(),
                'notes' => 'تم إنشاء الطلب من السلة',
            ]);

            // إفراغ السلة من البنود التي تحولت لطلب فقط (F-23): بند أُضيف
            // والتزم أثناء المعاملة لا يجب أن يمسحه مسحٌ كامل للسلة
            $orderedItemIds = array_values(array_unique(array_map(
                fn (array $line): int => (int) $line['item_id'],
                $lines,
            )));
            if ($orderedItemIds !== []) {
                $cart->items()->whereIn('id', $orderedItemIds)->delete();
            }
            $cart->update(['coupon_id' => null]);

            $created = $order->fresh(['items', 'statusHistories']);
            OrderCreated::dispatch($created);

            return $created;
        });
    }

    /**
     * بوابة الحالة الوحيدة. $expectedFrom (F-11): حالات مصدر يفترضها
     * المستدعي على نسخته غير المقفولة — تُفحص بعد إعادة القراءة بالقفل،
     * فسباق (عميل يلغي × أدمن يجهّز) لا يمر من فحص قديم.
     * $returnEffects (المرحلة 9): عند false ينتقل الطلب إلى Returned بلا
     * إعادة مخزون وبلا علم استرداد — مسار RMA سوّى المخزون عند الاستلام
     * الفعلي والمال عند رد المبلغ، فتكرار الأثر هنا ازدواج.
     *
     * @param  array<int, OrderStatus>|null  $expectedFrom
     */
    public function changeStatus(Order $order, OrderStatus|string $toStatus, ?Model $actor = null, ?string $notes = null, ?array $expectedFrom = null, bool $returnEffects = true): Order
    {
        $to = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $to, $actor, $notes, $expectedFrom, $returnEffects): Order {
            // إعادة قراءة الطلب بقفل صف: النسخة الممرَّرة قد تكون قديمة،
            // وكل الانتقالات على نفس الطلب يجب أن تتسلسل (C1)
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if ($expectedFrom !== null && ! in_array($from, $expectedFrom, true)) {
                throw new BusinessException("تغيّرت حالة الطلب أثناء العملية (أصبحت {$from->value})؛ أعد المحاولة.");
            }

            if ($from === $to) {
                return $order;
            }

            if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
                throw new BusinessException("انتقال حالة غير مسموح: من ({$from->value}) إلى ({$to->value}).");
            }

            $actorId = $this->inventoryActorId($actor);

            if ($to === OrderStatus::Returned && $returnEffects
                && ReturnRequest::query()->where('order_id', $order->id)->whereNotIn('status', [
                    ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value,
                ])->exists()) {
                throw new BusinessException('للطلب مرتجعات قائمة؛ أكمل الاستلام والاسترداد من إدارة المرتجعات لمنع تكرار المخزون.');
            }

            // آثار المخزون بحسب الانتقال
            $extraNote = null;
            if ($to === OrderStatus::Shipped) {
                $this->shipOrder($order, $actorId);
            } elseif ($to === OrderStatus::Cancelled
                && in_array($from, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                // الحجز صار من لحظة الإنشاء (F-01) فيُفك من Pending أيضًا
                $this->releaseReservations($order, $actorId, 'إلغاء الطلب');
            } elseif ($to === OrderStatus::Returned
                && in_array($from, [OrderStatus::Shipped, OrderStatus::Delivered], true)
                && $returnEffects) {
                // إعادة البضاعة المشحونة للمخزون بحركات sale_return (M13) —
                // تُتخطى في مسار RMA لأن الاستلام الفعلي سوّاها بالفعل (مرحلة 9)
                if (! $this->returnSalesToStock($order, $actorId)) {
                    $extraNote = 'لا توجد حركات بيع مسجلة؛ يتطلب المرتجع استلامًا يدويًا في المستودع.';
                }
            }

            if ($to === OrderStatus::Cancelled) {
                // عكس استخدام الكوبون حتى لا يُحرم العميل منه بإلغاء (M13)
                $this->reverseCouponUsage($order);
                if ($order->payment_status === 'paid') {
                    $order->update(['requires_refund' => true]);
                }
            }

            // مرتجع لطلب مدفوع يحتاج استردادًا أيضًا، لا الإلغاء فقط (F-14) —
            // في مسار RMA الاسترداد اكتمل داخل طلب المرتجع نفسه فلا علم هنا
            if ($to === OrderStatus::Returned && $order->payment_status === 'paid' && $returnEffects) {
                $order->update(['requires_refund' => true]);
            }

            if ($order->payment_method === 'wallet' && $order->payment_status === 'paid'
                && ($to === OrderStatus::Cancelled || ($to === OrderStatus::Returned && $returnEffects))) {
                $payment = CustomerWalletTransaction::query()->where('reference_type', 'order')->where('reference_id', $order->id)
                    ->where('type', 'checkout_payment')->first();
                if ($payment !== null) {
                    app(WalletService::class)->record($payment->customer_id, -(float) $payment->amount,
                        'order_reversal', 'order', $order->id, $actorId);
                    $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
                }
            }

            $order->update(['status' => $to]);

            // تسوية COD عند التسليم من أي مسار (أدمن يدوي أو ويب هوك) —
            // الأثر هنا في بوابة الحالة لا في ويب هوك الشحن فقط (F-14)
            if ($to === OrderStatus::Delivered
                && $order->payment_method === 'cod'
                && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }

            // notes عمود varchar(500): ملاحظة الأدمن (حتى 500) مع الملحق قد
            // تتجاوزه فتنفجر بحركة إرجاع بلا حركات بيع — تُقصّ على 500 (F-28)
            $historyNotes = trim(($notes ?? '').($extraNote !== null ? ' — '.$extraNote : '')) ?: $notes;

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => $historyNotes !== null ? mb_substr($historyNotes, 0, 500) : null,
            ]);

            // التوزيع ناقص إن غاب فرع الطلب أو بقي بند بلا فرع: الاعتماد على
            // allocated_branch_id وحده يفشل عندما تُوزَّع البنود في فروع
            // مختلفة فيبقى حقل الطلب فارغًا (F1)
            if ($to === OrderStatus::Confirmed && ! $this->isAllocated($order)) {
                $this->allocationService->allocate($order, performedBy: $actorId);
            }

            return $order->fresh(['items', 'statusHistories']);
        });
    }

    /**
     * كفاية التوزيع: يكفي أن يكون لفرع الطلب قيمة أو أن يكون كل بند موزّعًا
     * فعليًا في فرع ما (F1) — التوزيع الجزئي (بند بلا فرع) يُعيد الاستدعاء.
     */
    private function isAllocated(Order $order): bool
    {
        if ($order->allocated_branch_id !== null) {
            return true;
        }

        $distribution = $order->items()
            ->selectRaw('COUNT(*) AS total, COUNT(allocated_branch_id) AS allocated')
            ->first();

        if ($distribution === null || (int) $distribution->total === 0) {
            return false;
        }

        return (int) $distribution->total === (int) $distribution->allocated;
    }

    /** عميل جملة/توزيع معتمد — يُستثنى من سقوف التجزئة (H2). */
    private function isWholesaleApproved(?Customer $customer): bool
    {
        return $customer !== null
            && $customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true);
    }

    /**
     * توزيع مبلغ على أوزان بطريقة «أكبر باقٍ» بعدد صحيح من القروش (L5):
     * مجموع الحصص يساوي المبلغ بالضبط، ولا حصة سالبة بسبب التقريب.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function distributeLargestRemainder(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }
        $totalCents = (int) round($total * 100);
        $weightSum = array_sum($weights);
        if ($totalCents <= 0 || $weightSum <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $floorCents = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $i => $weight) {
            $exact = $totalCents * ((float) $weight / $weightSum);
            $floor = (int) floor($exact);
            $floorCents[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $assigned += $floor;
        }

        $left = $totalCents - $assigned;
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $floorCents[$i]++;
            $left--;
        }

        ksort($floorCents);

        return array_map(fn (int $cents): float => $cents / 100, $floorCents);
    }

    /**
     * الشحن: لكل حركة حجز — فك الحجز من reserved وخصم فعلي sale من on_hand
     * على نفس صف الرصيد، وزيادة عدّاد مبيعات المنتج.
     */
    private function shipOrder(Order $order, ?int $actorId): void
    {
        $reservations = $this->reserveMovements($order);
        if ($reservations->isEmpty()) {
            throw new BusinessException('لا توجد حجوزات مخزون لهذا الطلب؛ لا يمكن الشحن قبل التأكيد والتوزيع.');
        }

        foreach ($reservations as $movement) {
            $base = [
                'product_variant_id' => $movement->product_variant_id,
                'branch_id' => $movement->branch_id,
                'location_id' => $movement->location_id,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
            ];
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $movement->quantity,
                'notes' => "فك حجز عند شحن الطلب {$order->order_number}",
            ]);
            $this->inventoryService->recordMovement([...$base,
                'type' => StockMovementType::Sale,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1 * (float) $movement->quantity,
                'notes' => "بيع عند شحن الطلب {$order->order_number}",
            ]);
        }

        foreach ($order->items as $item) {
            $item->variant?->product?->increment('sales_count', (int) round((float) $item->qty));
        }
    }

    /** إلغاء طلب مُحجوز: فك كل حجوزاته فتعود الكميات متاحة للبيع. */
    private function releaseReservations(Order $order, ?int $actorId, string $reason): void
    {
        foreach ($this->reserveMovements($order) as $movement) {
            $this->inventoryService->recordMovement([
                'product_variant_id' => $movement->product_variant_id,
                'branch_id' => $movement->branch_id,
                'location_id' => $movement->location_id,
                'type' => StockMovementType::ReleaseReserve,
                'affected_bucket' => StockBucket::Reserved,
                'quantity' => -1 * (float) $movement->quantity,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
                'notes' => "{$reason} {$order->order_number}",
            ]);
        }
    }

    /**
     * إعادة بضاعة طلب مشحون/مُسلَّم إلى المخزون: لكل حركة بيع حركة
     * sale_return معاكسة على نفس الرصيد، وتنقيص عدّاد المبيعات (M13).
     * ترجع false إذا لم توجد حركات بيع (يحتاج استلامًا يدويًا).
     */
    private function returnSalesToStock(Order $order, ?int $actorId): bool
    {
        $sales = StockMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->where('type', StockMovementType::Sale)
            ->orderBy('id')
            ->get();
        if ($sales->isEmpty()) {
            return false;
        }

        foreach ($sales as $movement) {
            $this->inventoryService->recordMovement([
                'product_variant_id' => $movement->product_variant_id,
                'branch_id' => $movement->branch_id,
                'location_id' => $movement->location_id,
                'type' => StockMovementType::SaleReturn,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1 * (float) $movement->quantity,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'created_by' => $actorId,
                'notes' => "مرتجع مبيعات للطلب {$order->order_number}",
            ]);
        }

        foreach ($order->items as $item) {
            if ($item->variant?->product_id) {
                Product::query()->whereKey($item->variant->product_id)
                    ->update(['sales_count' => DB::raw('GREATEST(sales_count - '.(int) round((float) $item->qty).', 0)')]);
            }
        }

        return true;
    }

    /** عكس تسجيل استخدام الكوبون عند إلغاء الطلب: حذف السجل وإنقاص العدّاد (M13). */
    private function reverseCouponUsage(Order $order): void
    {
        foreach (CouponUsage::query()->where('order_id', $order->id)->get() as $usage) {
            Coupon::query()->whereKey($usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            $usage->delete();
        }
    }

    /** حركات الحجز الأصلية للطلب من الدفتر (مصدر الحقيقة لمواضع الحجز ومواقعه). */
    private function reserveMovements(Order $order): Collection
    {
        return StockMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->where('type', StockMovementType::Reserve)
            ->orderBy('id')
            ->get();
    }

    // التخزين على مستوى الـ instance لا static (F-27): في Octane/العمال
    // طويلة العمر كانت قيمة static تبقى عالقة بين الطلبات وبين الاختبارات
    private ?int $systemActorId = null;

    private bool $systemActorResolved = false;

    /**
     * منفّذ حركات المخزون: الموظف الفاعل نفسه؛ وعند غيابه (عميل/ويب هوك)
     * يُنسب التنفيذ لمستخدم النظام المخصص (M19) — لا لأول Super Admin،
     * حتى يبقى سجل التدقيق صادقًا. يُحسم مرة واحدة لكل نسخة خدمة.
     */
    private function inventoryActorId(?Model $actor): ?int
    {
        if ($actor instanceof User) {
            return (int) $actor->getKey();
        }

        if (! $this->systemActorResolved) {
            $this->systemActorResolved = true;
            $systemId = User::query()
                ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
                ->value('id');
            $this->systemActorId = $systemId !== null ? (int) $systemId : null;
        }
        if ($this->systemActorId !== null) {
            return $this->systemActorId;
        }

        // رجوع احتياطي إن لم تُزرع هجرة مستخدم النظام بعد
        $adminId = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))
            ->orderBy('id')
            ->value('id');

        return $adminId !== null ? (int) $adminId : (auth()->user() instanceof User ? (int) auth()->id() : null);
    }
}
