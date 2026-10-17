<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Coupon;

/**
 * التحقق من الكوبونات وربطها بالسلة وحساب قيمة خصمها.
 * قيمة الخصم تُحسب على إجمالي السلة بعد أسعار PricingService وقبل الشحن والضريبة.
 */
class CouponService
{
    /**
     * يتحقق من الكوبون ويربطه بالسلة ويرجع قيمة الخصم على الإجمالي المعطى.
     */
    public function validateAndApply(Cart $cart, string $code, float $subtotal): float
    {
        $coupon = $this->findRedeemable($code, $subtotal);

        $cart->update(['coupon_id' => $coupon->id]);

        return $this->discountFor($coupon, $subtotal);
    }

    /** يتحقق من كوبون مرتبط بسلة (عند إتمام الشراء) ويرجعه أو يرمي استثناءً. */
    public function redeemableById(int $couponId, float $subtotal): Coupon
    {
        $coupon = Coupon::query()->find($couponId);
        if ($coupon === null) {
            throw new BusinessException('الكوبون المرتبط بالسلة لم يعد موجودًا.');
        }
        $this->assertRedeemable($coupon, $subtotal);

        return $coupon;
    }

    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = $coupon->type === 'percentage'
            ? $subtotal * (float) $coupon->value / 100
            : (float) $coupon->value;

        if ($coupon->max_discount_amount !== null) {
            $discount = min($discount, (float) $coupon->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    private function findRedeemable(string $code, float $subtotal): Coupon
    {
        $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if ($coupon === null) {
            throw new BusinessException('كود الخصم غير صحيح.');
        }
        $this->assertRedeemable($coupon, $subtotal);

        return $coupon;
    }

    private function assertRedeemable(Coupon $coupon, float $subtotal): void
    {
        if (! $coupon->isRedeemableNow()) {
            throw new BusinessException('كود الخصم غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.');
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new BusinessException('قيمة السلة أقل من الحد الأدنى لاستخدام هذا الكوبون.');
        }
    }
}
