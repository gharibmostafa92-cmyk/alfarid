<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

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
     * سلة الزائر/العميل الحالية — تُنشأ عند الحاجة.
     * لو وُجدت سلة زائر بالتوكن وعميل مسجل، تُدمج فيها أولًا.
     */
    public function resolveCart(?Customer $customer, ?string $guestToken): Cart
    {
        if ($customer !== null) {
            $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);
            if ($guestToken !== null && $guestToken !== $cart->cart_token) {
                $this->mergeGuestCart($customer, $guestToken);
                $cart = $cart->fresh();
            }

            return $cart->loadMissing('items');
        }

        $cart = $guestToken !== null
            ? Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')->first()
            : null;

        return ($cart ?? Cart::create([]))->loadMissing('items');
    }

    /** دمج سلة زائر (بالتوكن) في سلة حساب العميل — تُستدعى عند تسجيل الدخول. */
    public function mergeGuestCart(Customer $customer, ?string $guestToken): void
    {
        if ($guestToken === null) {
            return;
        }
        $guest = Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')->first();
        if ($guest === null || ! $guest->items()->exists()) {
            $guest?->delete();

            return;
        }

        DB::transaction(function () use ($customer, $guest): void {
            $account = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);
            foreach ($guest->items as $item) {
                $existing = $account->items()->where('product_variant_id', $item->product_variant_id)->first();
                if ($existing !== null) {
                    $existing->update(['qty' => (float) $existing->qty + (float) $item->qty]);
                } else {
                    $account->items()->create([
                        'product_variant_id' => $item->product_variant_id,
                        'qty' => $item->qty,
                    ]);
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
        $variant = $this->purchasableVariant($variantId);
        $existing = $cart->items()->where('product_variant_id', $variantId)->first();
        $targetQty = ($existing !== null ? (float) $existing->qty : 0) + $qty;
        $this->assertAvailable($variantId, $targetQty);
        $this->assertPriced($variant, $customer, $targetQty);

        if ($existing !== null) {
            $existing->update(['qty' => $targetQty]);
        } else {
            $cart->items()->create(['product_variant_id' => $variantId, 'qty' => $qty]);
        }

        return $cart->fresh('items');
    }

    public function updateItemQty(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        if ($qty <= 0) {
            return $this->removeItem($cart, $variantId);
        }
        $variant = $this->purchasableVariant($variantId);
        $this->assertAvailable($variantId, $qty);
        $this->assertPriced($variant, $customer, $qty);

        $cart->items()->updateOrCreate(
            ['product_variant_id' => $variantId],
            ['qty' => $qty],
        );

        return $cart->fresh('items');
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
                    'variant' => $variant,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'base_price' => $base,
                    'line_discount' => round(($base - $unit) * $qty, 2),
                    'line_total' => round($unit * $qty, 2),
                ];
            } catch (BusinessException) {
                $lines[] = [
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
    public function totals(Cart $cart, ?Customer $customer): array
    {
        $lines = $this->pricedLines($cart, $customer);
        $subtotal = 0.0;
        $pricingDiscount = 0.0;
        $itemsTotal = 0.0;
        foreach ($lines as $line) {
            if ($line['unit_price'] === null) {
                continue;
            }
            $subtotal += (float) $line['base_price'] * $line['qty'];
            $pricingDiscount += $line['line_discount'];
            $itemsTotal += $line['line_total'];
        }

        $couponDiscount = 0.0;
        $couponCode = null;
        if ($cart->coupon_id !== null && $itemsTotal > 0) {
            try {
                $coupon = $this->coupons->redeemableById((int) $cart->coupon_id, $itemsTotal);
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
            'totals' => $this->totals($cart, $customer),
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
