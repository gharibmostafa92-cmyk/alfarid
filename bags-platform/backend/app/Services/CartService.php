<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * سلة التسوق للزائر والعميل المسجل.
 * - الزائر: سلة مربوطة بـ cart_token (يُرسل في هيدر X-Cart-Session).
 * - العميل: سلة مربوطة بحسابه؛ وعند تسجيل الدخول تُدمج سلة الزائر فيها.
 * الأسعار دائمًا لحظية من PricingService (لا تُخزّن في السلة أبدًا).
 */
class CartService
{
    public function __construct(
        protected PricingService $pricing,
        protected StoreCatalogService $catalog,
        protected CouponService $coupons,
    ) {}

    /**
     * سلة الزائر/العميل الحالية.
     * - التوكن غير الـ UUID يُتجاهل تمامًا (M2)؛ وتوكن أي سلة جديدة
     *   يُولّد على الخادم دائمًا (Cart::booted) ولا يُقبل مقترح العميل.
     * - بوضع القراءة ($create=false) لا يُحفظ أي صف: تُرجع سلة فارغة
     *   افتراضية حتى لا تملأ الزواحف جدول carts (M3).
     * لو وُجدت سلة زائر بالتوكن وعميل مسجل، تُدمج فيها أولًا.
     */
    public function resolveCart(?Customer $customer, ?string $guestToken, bool $create = true): Cart
    {
        $guestToken = $guestToken !== null && Str::isUuid($guestToken) ? $guestToken : null;

        if ($customer !== null) {
            $cart = $create
                ? $this->accountCart($customer)
                : (Cart::query()->where('customer_id', $customer->id)->first() ?? new Cart(['customer_id' => $customer->id]));
            if ($create && $guestToken !== null && $guestToken !== $cart->cart_token) {
                $this->mergeGuestCart($customer, $guestToken);
                $cart = $cart->fresh();
            }

            return $cart->loadMissing('items');
        }

        $cart = $guestToken !== null
            ? Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')->first()
            : null;
        if ($cart !== null) {
            return $cart->loadMissing('items');
        }

        return ($create ? Cart::create([]) : new Cart)->loadMissing('items');
    }

    /**
     * سلة حساب العميل الوحيدة — القيد الفريد على customer_id يحسم السباق (M6).
     * createOrFirst يغلّف الإدراج في savepoint داخل المعاملات (F-10):
     * firstOrCreate العادي إذا فشل إدراجه هنا — والدمج كله داخل transaction
     * خارجية — ترك المعاملة aborted (25P02) فخرج 500 رغم الـ catch.
     */
    private function accountCart(Customer $customer): Cart
    {
        return Cart::query()->where('customer_id', $customer->id)->first()
            ?? Cart::query()->createOrFirst(['customer_id' => $customer->id]);
    }

    /** دمج سلة زائر (بالتوكن) في سلة حساب العميل — تُستدعى عند تسجيل الدخول. */
    public function mergeGuestCart(Customer $customer, ?string $guestToken): void
    {
        $guestToken = $guestToken !== null && Str::isUuid($guestToken) ? $guestToken : null;
        if ($guestToken === null) {
            return;
        }

        // كل الدمج داخل transaction واحدة وسلة الزائر مقفولة: طلبات متوازية
        // بعد تسجيل الدخول بنفس التوكن لا تضاعف الكميات (M6)
        DB::transaction(function () use ($customer, $guestToken): void {
            $guest = Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')
                ->lockForUpdate()->first();
            if ($guest === null) {
                return;
            }
            if (! $guest->items()->exists()) {
                $guest->delete();

                return;
            }

            $account = $this->accountCart($customer);
            // الدمج يحترم سقوف السلة (M2): بغير ذلك بضع سلال زائر بحد البنود
            // تُنتج سلة حساب بمئات البنود فتُلغي حماية F15
            $maxLines = (int) config('store.max_cart_lines', 50);
            $lineCap = $this->lineQtyCap($customer);
            $accountQty = $account->items()->pluck('qty', 'product_variant_id')
                ->map(fn ($qty): float => (float) $qty)->all();
            $accountLines = count($accountQty);

            foreach ($guest->items as $item) {
                $variantId = (int) $item->product_variant_id;
                $existingQty = $accountQty[$variantId] ?? null;
                $isNew = $existingQty === null;

                // بنود جديدة فوق السقف تُتجاهل (لا تُفشل تسجيل الدخول)
                if ($isNew && $maxLines > 0 && $accountLines >= $maxLines) {
                    continue;
                }

                $qty = (float) $item->qty;
                if ($lineCap > 0) {
                    $allowed = $lineCap - (float) ($existingQty ?? 0);
                    if ($allowed <= 0) {
                        continue;
                    }
                    $qty = min($qty, $allowed);
                }

                // UPSERT ذرّي: دمج متزامن لا يصطدم بقيد التفرد (F-09)
                CartItem::query()->upsert(
                    [[
                        'cart_id' => $account->id,
                        'product_variant_id' => $variantId,
                        'qty' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['cart_id', 'product_variant_id'],
                    ['qty' => DB::raw('cart_items.qty + EXCLUDED.qty'), 'updated_at' => now()],
                );

                $accountQty[$variantId] = (float) ($existingQty ?? 0) + $qty;
                if ($isNew) {
                    $accountLines++;
                }
            }
            if ($account->coupon_id === null && $guest->coupon_id !== null) {
                $account->update(['coupon_id' => $guest->coupon_id]);
            }
            $guest->items()->delete();
            $guest->delete();
        });
    }

    public function addItem(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        return DB::transaction(function () use ($cart, $customer, $variantId, $qty): Cart {
            // قفل صف السلة (F19): إضافتان متزامنتان تتسلسلان فلا تُحسب
            // الكمية على قراءة قديمة ولا يُتجاوز سقف البنود
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            $variant = $this->purchasableVariant($variantId);
            $existing = $cart->items()->where('product_variant_id', $variantId)->first();

            // سقف أصناف السلة (F15): سلة بآلاف البنود تُثقل التسعير وكل عرض
            if ($existing === null) {
                $maxLines = (int) config('store.max_cart_lines', 50);
                if ($maxLines > 0 && $cart->items()->count() >= $maxLines) {
                    throw new BusinessException("بلغت سلة التسوق سقف الأصناف ({$maxLines})؛ احذف صنفًا قبل الإضافة.");
                }
            }

            $targetQty = ($existing !== null ? (float) $existing->qty : 0) + $qty;
            $this->assertLineQty($customer, $targetQty);
            $this->assertAvailable($variantId, $targetQty);
            $this->assertPriced($variant, $customer, $targetQty);

            // كتابة ذرّية UPSERT (F-09): نقرتان متزامنتان على «أضف للسلة»
            // كانتا تصطدمان بقيد التفرد عند الإدراج الثاني فتخرج 500 —
            // ON CONFLICT تجمع الكمية على الصف نفسه. القراءة أعلاه تبقى
            // للتحقق من حد التوفر فقط
            CartItem::query()->upsert(
                [[
                    'cart_id' => $cart->id,
                    'product_variant_id' => $variantId,
                    'qty' => $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['cart_id', 'product_variant_id'],
                ['qty' => DB::raw('cart_items.qty + EXCLUDED.qty'), 'updated_at' => now()],
            );

            return $cart->fresh('items');
        });
    }

    public function updateItemQty(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        if ($qty <= 0) {
            return $this->removeItem($cart, $variantId);
        }

        return DB::transaction(function () use ($cart, $customer, $variantId, $qty): Cart {
            // قفل صف السلة (F19) بنفس منطق addItem
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $variant = $this->purchasableVariant($variantId);
            $this->assertLineQty($customer, $qty);
            $this->assertAvailable($variantId, $qty);
            $this->assertPriced($variant, $customer, $qty);

            // UPSERT ذرّي بنفس منطق addItem — updateOrCreate كان يصطدم
            // بقيد التفرد تحت التزامن (F-09)، وهنا القيمة مطلقة لا تراكمية
            CartItem::query()->upsert(
                [[
                    'cart_id' => $cart->id,
                    'product_variant_id' => $variantId,
                    'qty' => $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['cart_id', 'product_variant_id'],
                ['qty' => $qty, 'updated_at' => now()],
            );

            return $cart->fresh('items');
        });
    }

    public function removeItem(Cart $cart, int $variantId): Cart
    {
        $cart->items()->where('product_variant_id', $variantId)->delete();

        return $cart->fresh('items');
    }

    /**
     * بنود السلة بأسعار لحظية من PricingService.
     * بند غير قابل للتسعير يرجع بـ unit_price = null (العرض يتجاوزه،
     * وإتمام الشراء يرفضه — يتحقق المستدعي).
     *
     * @return array<int, array{variant: ProductVariant, qty: float, unit_price: float|null, base_price: float|null, line_discount: float, line_total: float}>
     */
    public function pricedLines(Cart $cart, ?Customer $customer): array
    {
        $cart->loadMissing('items.variant.product');
        $lines = [];
        foreach ($cart->items as $item) {
            $variant = $item->variant;
            $qty = (float) $item->qty;
            try {
                $resolved = $this->pricing->resolve($variant, $customer, $qty);
                $unit = (float) $resolved['unit_price'];
                $base = (float) $resolved['base_price'];
                $lines[] = [
                    'item_id' => $item->id,
                    'variant' => $variant,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'base_price' => $base,
                    'line_discount' => round(($base - $unit) * $qty, 2),
                    'line_total' => round($unit * $qty, 2),
                ];
            } catch (BusinessException) {
                $lines[] = [
                    'item_id' => $item->id,
                    'variant' => $variant,
                    'qty' => $qty,
                    'unit_price' => null,
                    'base_price' => null,
                    'line_discount' => 0.0,
                    'line_total' => 0.0,
                ];
            }
        }

        return $lines;
    }

    /**
     * ملخص السلة المالي بالأسعار اللحظية.
     *
     * @return array{subtotal: float, pricing_discount: float, items_total: float, coupon_discount: float, total: float, coupon_code: string|null}
     */
    /**
     * @param  array<int, array<string, mixed>>|null  $lines  بنود محسوبة مسبقًا
     *                                                        لتجنب إعادة التسعير (M15)
     */
    public function totals(Cart $cart, ?Customer $customer, ?array $lines = null): array
    {
        $lines ??= $this->pricedLines($cart, $customer);
        $subtotal = 0.0;
        $itemsTotal = 0.0;
        foreach ($lines as $line) {
            if ($line['unit_price'] === null) {
                continue;
            }
            // كل سطر يُقرَّب أولًا ثم تُجمع القيم المقرّبة (M17)
            $subtotal += round((float) $line['base_price'] * $line['qty'], 2);
            $itemsTotal += $line['line_total'];
        }

        // الخصم السعري = الفرق بين الإجماليين المقرّبين، لا مجموع فروق
        // السطور المقرّبة (F-26) حتى لا يظهر فرق قرش بين العرض والمحصّل.
        // ‏(L6) سعر مخصص أعلى من الأساسي لا يصنع خصمًا سالبًا: يُرفع المرجع
        // لسعر البيع الفعلي، فلا يزيد الخصم السالب الإجمالي المحصّل.
        if ($subtotal < $itemsTotal) {
            $subtotal = $itemsTotal;
            $pricingDiscount = 0.0;
        } else {
            $pricingDiscount = round($subtotal - $itemsTotal, 2);
        }

        $couponDiscount = 0.0;
        $couponCode = null;
        if ($cart->coupon_id !== null && $itemsTotal > 0) {
            try {
                $coupon = $this->coupons->redeemableById((int) $cart->coupon_id, $itemsTotal, $customer);
                $couponDiscount = $this->coupons->discountFor($coupon, $itemsTotal);
                $couponCode = $coupon->code;
            } catch (BusinessException) {
                // كوبون لم يعد صالحًا: يُعرض الملخص بدونه (إتمام الشراء سيعيد التحقق)
            }
        }

        return [
            'subtotal' => round($subtotal, 2),
            'pricing_discount' => round($pricingDiscount, 2),
            'items_total' => round($itemsTotal, 2),
            'coupon_discount' => $couponDiscount,
            'total' => round($itemsTotal - $couponDiscount, 2),
            'coupon_code' => $couponCode,
        ];
    }

    /**
     * إعادة التحقق من قابلية البيع لكل بند لحظة الشراء بشكل مجمّع (M14):
     * متغير معطّل أو منتج غير منشور/محذوف ناعمًا بعد الإضافة يوقف الطلب.
     *
     * @param  array<int, array{variant: ProductVariant, qty: float}>  $lines
     */
    public function assertLinesPurchasable(array $lines): void
    {
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $variants = ProductVariant::query()->with('product')
            ->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($lines as $line) {
            $variant = $variants->get($line['variant']->id) ?? $line['variant'];
            if (! $variant->is_active || $variant->product === null
                || $variant->product->status !== ProductStatus::Published || $variant->product->trashed()) {
                throw new BusinessException("الصنف ({$variant->sku}) لم يعد متاحًا للبيع؛ أزله من السلة وأعد المحاولة.");
            }
        }
    }

    /**
     * إعادة التحقق من توفر بنود مُسعّرة لحظة الشراء.
     *
     * @param  array<int, array{variant: ProductVariant, qty: float}>  $lines
     */
    public function assertLinesAvailable(array $lines): void
    {
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $map = $this->catalog->availabilityMap($ids);
        foreach ($lines as $line) {
            if (($map[$line['variant']->id] ?? 0) < $line['qty']) {
                throw new BusinessException("الكمية المتاحة من الصنف ({$line['variant']->sku}) لم تعد كافية لإتمام الطلب.");
            }
        }
    }

    /**
     * تمثيل السلة للعرض: البنود بأسعار ومتاح لحظي + الملخص المالي.
     *
     * @return array<string, mixed>
     */
    public function present(Cart $cart, ?Customer $customer): array
    {
        $lines = $this->pricedLines($cart, $customer);
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $availability = $this->catalog->availabilityMap($ids);

        $items = [];
        foreach ($lines as $line) {
            $variant = $line['variant'];
            $items[] = [
                'id' => $line['item_id'],
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name_ar,
                'sku' => $variant->sku,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'base_price' => $line['base_price'],
                'line_discount' => $line['line_discount'],
                'line_total' => $line['line_total'],
                'available' => $availability[$variant->id] ?? 0,
                'priced' => $line['unit_price'] !== null,
            ];
        }

        return [
            'id' => $cart->id,
            'cart_token' => $cart->cart_token,
            'customer_id' => $cart->customer_id,
            'items' => $items,
            'totals' => $this->totals($cart, $customer, $lines),
        ];
    }

    private function purchasableVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()->with('product')->find($variantId);
        if ($variant === null || ! $variant->is_active || $variant->product === null
            || $variant->product->status !== ProductStatus::Published || $variant->product->trashed()) {
            throw new BusinessException('الصنف غير متاح للشراء.');
        }

        return $variant;
    }

    /** سقف كمية البند الواحد (H2): للزوار، وللمسجّلين غير الجملة سقف تجزئة. */
    private function assertLineQty(?Customer $customer, float $qty): void
    {
        $cap = $this->lineQtyCap($customer);
        if ($cap > 0 && $qty > $cap) {
            throw new BusinessException($customer === null
                ? "سقف كمية البند الواحد للزوار ({$cap}) — سجّل الدخول للكميات الأكبر."
                : "سقف كمية البند الواحد ({$cap}) — للكميات الأكبر تواصل مع مسار الجملة.");
        }
    }

    /** سقف كمية السطر الفعّال للهوية: 0 = بلا سقف (عملاء الجملة المعتمدون). */
    private function lineQtyCap(?Customer $customer): float
    {
        if ($customer === null) {
            return (float) config('store.guest_max_line_qty', 10);
        }
        if ($customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            return 0.0;
        }

        return (float) config('store.retail_max_line_qty', 50);
    }

    private function assertAvailable(int $variantId, float $qty): void
    {
        $available = $this->catalog->availabilityMap([$variantId])[$variantId] ?? 0;
        if ($available < $qty) {
            throw new BusinessException('الكمية المطلوبة غير متاحة حاليًا لهذا الصنف.');
        }
    }

    private function assertPriced(ProductVariant $variant, ?Customer $customer, float $qty): void
    {
        $this->pricing->resolve($variant, $customer, $qty); // يرمي BusinessException عند غياب السعر
    }
}
