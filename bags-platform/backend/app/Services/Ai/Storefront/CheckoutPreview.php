<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\SettingService;
use App\Services\ShippingQuoteService;
use App\Services\StorePaymentPolicy;

/**
 * Dry-run checkout validation: replicates order creation checks without stock reservation or database writes.
 */
final class CheckoutPreview
{
    public function __construct(
        private CartService $carts,
        private CouponService $coupons,
        private ShippingQuoteService $shipping,
        private SettingService $settings,
        private StorePaymentPolicy $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function diagnose(Cart $cart, ?Customer $customer, array $data): array
    {
        $cart->loadMissing('items');
        if ($cart->items->isEmpty()) {
            return $this->stop('السلة فارغة؛ لا يمكن إنشاء طلب.');
        }
        foreach ($cart->items as $item) {
            $failed = $this->guard(fn () => $this->carts->assertWholeQuantity((float) $item->qty));
            if ($failed !== null) {
                return $failed;
            }
        }
        $lines = $this->carts->pricedLines($cart, $customer);
        foreach ($lines as $line) {
            if ($line['unit_price'] === null) {
                return $this->stop("الصنف ({$line['variant']->sku}) غير مُسعَّر؛ لا يمكن إتمام الطلب.");
            }
            if ($line['line_total'] <= 0) {
                return $this->stop("قيمة الصنف ({$line['variant']->sku}) صفر؛ راجع الكمية أو السعر.");
            }
        }
        $wholesale = $customer?->isWholesaleApproved() ?? false;
        if (! $wholesale) {
            $cap = $this->carts->lineQtyCap($customer);
            if ($cap > 0) {
                foreach ($lines as $line) {
                    if ((float) $line['qty'] > $cap) {
                        return $this->stop($customer === null
                            ? "سقف كمية البند الواحد للزوار ({$cap}) — سجّل الدخول للكميات الأكبر."
                            : "سقف كمية البند الواحد ({$cap}) — للكميات الأكبر تواصل مع مسار الجملة.");
                    }
                }
            }
        }
        if ($customer === null) {
            $pendingCap = (int) config('store.guest_pending_orders_cap', 3);
            $phone = isset($data['recipient_phone']) ? Customer::normalizePhone((string) $data['recipient_phone']) : null;
            if ($pendingCap > 0 && is_string($phone) && $phone !== '') {
                $pending = Order::query()->whereNull('customer_id')->where('status', OrderStatus::Pending)->where('guest_phone_normalized', $phone)->count();
                if ($pending >= $pendingCap) {
                    return $this->stop('لديك طلبات معلّقة كثيرة على هذا الرقم؛ أكملها أو ألغِها قبل طلب جديد.');
                }
            }
        } elseif (! $wholesale) {
            $pendingCap = (int) config('store.customer_pending_orders_cap', 5);
            if ($pendingCap > 0) {
                $pending = Order::query()->where('customer_id', $customer->id)->where('status', OrderStatus::Pending)->count();
                if ($pending >= $pendingCap) {
                    return $this->stop('لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.');
                }
            }
        }
        foreach ([
            fn () => $this->carts->assertLinesPurchasable($lines),
            fn () => $this->carts->assertLinesAvailable($lines),
        ] as $check) {
            $failed = $this->guard($check);
            if ($failed !== null) {
                return $failed;
            }
        }
        $totals = $this->carts->totals($cart, $customer, $lines);
        $coupon = null;
        $couponDiscount = 0.0;
        if ($cart->coupon_id !== null) {
            try {
                $coupon = $this->coupons->redeemableById(
                    (int) $cart->coupon_id,
                    $totals['items_total'],
                    $customer,
                    $customer === null ? ($data['recipient_phone'] ?? null) : null,
                );
            } catch (BusinessException $e) {
                return $this->stop($e->getMessage());
            }
            $couponDiscount = $this->coupons->discountFor($coupon, $totals['items_total']);
        }
        $city = null;
        $shippingCost = 0.0;
        $codFee = 0.0;
        if (! empty($data['city_id'])) {
            $city = City::query()->where('is_active', true)->find((int) $data['city_id']);
            if ($city === null) {
                return $this->stop('مدينة التوصيل غير متاحة.');
            }
            $quote = $this->shipping->quoteForCity($city->id, $totals['items_total'] - $couponDiscount, ($data['payment_method'] ?? 'cod') === 'cod');
            $shippingCost = $quote['cost'];
            $codFee = $quote['cod_fee'];
        }
        if ($coupon !== null && $coupon->type === 'free_shipping' && $shippingCost > 0) {
            $shippingCost = 0.0;
        }
        $discountAmount = round($totals['pricing_discount'] + $couponDiscount, 2);
        $taxAmount = 0.0;
        if ($this->settings->getBool('tax_enabled') && ! $this->settings->getBool('prices_include_tax')) {
            $taxAmount = round(($totals['subtotal'] - $discountAmount) * (float) $this->settings->get('default_tax_rate', 0) / 100, 2);
        }
        $grand = round(($totals['subtotal'] - $discountAmount) + $shippingCost + $codFee + $taxAmount, 2);
        $methods = $this->payments->allowedFor($customer);
        if (! empty($data['payment_method']) && ! in_array($data['payment_method'], $methods, true)) {
            return $this->stop('طريقة الدفع غير متاحة لهذا الحساب.');
        }

        return [
            'ok' => true,
            'message' => null,
            'payment_url' => null,
            'payment_collection' => 'not_integrated',
            'would_reserve_stock' => false,
            'preview' => [
                'items_total' => $totals['items_total'],
                'coupon_discount' => $couponDiscount,
                'pricing_discount' => $totals['pricing_discount'],
                'shipping' => $shippingCost,
                'cod_fee' => $codFee,
                'tax' => $taxAmount,
                'grand_total' => $grand,
                'payment_methods' => $methods,
                'city_id' => $city?->id,
            ],
        ];
    }

    /** @param callable():void $check @return array<string, mixed>|null */
    private function guard(callable $check): ?array
    {
        try {
            $check();
        } catch (BusinessException $e) {
            return $this->stop($e->getMessage());
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function stop(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'payment_url' => null,
            'payment_collection' => 'not_integrated',
            'would_reserve_stock' => false,
            'preview' => null,
        ];
    }
}
