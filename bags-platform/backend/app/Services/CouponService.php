<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;

/**
 * التحقق من الكوبونات وربطها بالسلة وحساب خصمها وتسجيل استخدامها.
 * الفحوص: التفعيل، النافذة الزمنية، الحد الكلي للاستخدام، حد العميل
 * الواحد (usage_limit_per_customer — افتراضي 1)، والحد الأدنى للسلة.
 * الخصم النسبي: min(subtotal × value/100, max_discount_amount).
 * كوبون free_shipping خصمه المالي على البضاعة صفر — أثره إلغاء الشحن
 * عند إتمام الشراء (يُسجل مبلغ الشحن المُعفى في سجل الاستخدام).
 */
class CouponService
{
    public function validateAndApply(Cart $cart, string $code, float $subtotal, ?Customer $customer = null): float
    {
        $coupon = $this->findRedeemable($code, $subtotal, $customer);

        $cart->update(['coupon_id' => $coupon->id]);

        return $this->discountFor($coupon, $subtotal);
    }

    /** يتحقق من كوبون مرتبط بسلة (عند إتمام الشراء) ويرجعه أو يرمي استثناءً. */
    public function redeemableById(int $couponId, float $subtotal, ?Customer $customer = null, ?string $guestPhone = null): Coupon
    {
        $coupon = Coupon::query()->find($couponId);
        if ($coupon === null) {
            throw new BusinessException('الكوبون المرتبط بالسلة لم يعد موجودًا.');
        }
        $this->assertRedeemable($coupon, $subtotal, $customer, $guestPhone);

        return $coupon;
    }

    /** خصم البضاعة: النسبي بسقف، والثابت بحد الإجمالي، والشحن المجاني صفر هنا. */
    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        if ($subtotal <= 0 || $coupon->type === 'free_shipping') {
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

    /** تسجيل استخدام فعلي عند إتمام الطلب + تحديث العداد الكلي. */
    public function recordUsage(Coupon $coupon, Order $order, ?Customer $customer, float $discountAmount): CouponUsage
    {
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer?->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
            // هاتف الزائر الموحد يُخزَّن مع الاستخدام لفحص الحد لاحقًا في SQL (F-04)
            'guest_phone_normalized' => $customer === null && $order->guest_phone !== null
                ? Customer::normalizePhone($order->guest_phone)
                : null,
        ]);
        $coupon->increment('used_count');

        return $usage;
    }

    private function findRedeemable(string $code, float $subtotal, ?Customer $customer): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();
        if ($coupon === null) {
            throw new BusinessException('كود الخصم غير صحيح.');
        }
        $this->assertRedeemable($coupon, $subtotal, $customer);

        return $coupon;
    }

    private function assertRedeemable(Coupon $coupon, float $subtotal, ?Customer $customer, ?string $guestPhone = null): void
    {
        if (! $coupon->isRedeemableNow()) {
            throw new BusinessException('كود الخصم غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.');
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new BusinessException('قيمة السلة أقل من الحد الأدنى لاستخدام هذا الكوبون.');
        }
        if ((int) $coupon->usage_limit_per_customer <= 0) {
            return;
        }
        if ($customer !== null) {
            // استخدامات العميل المسجل — الطلبات الملغاة لا تحتسب
            $usedByCustomer = $coupon->usages()
                ->where('customer_id', $customer->id)
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->count();
            if ($usedByCustomer >= (int) $coupon->usage_limit_per_customer) {
                throw new BusinessException('وصلت لحد استخدام هذا الكوبون لحسابك.');
            }
        } elseif ($guestPhone !== null && trim($guestPhone) !== '') {
            // هوية الزائر هي رقم هاتفه الموحد الصيغة — تبديل cart_token لا
            // يتجاوز الحد (H3). العدّ في SQL على العمود المفهرس (F-04)
            // بدل مسح كل الاستخدامات أثناء إمساك قفل الكوبون
            $usedByPhone = $coupon->usages()
                ->where('guest_phone_normalized', Customer::normalizePhone($guestPhone))
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->count();
            if ($usedByPhone >= (int) $coupon->usage_limit_per_customer) {
                throw new BusinessException('وصلت لحد استخدام هذا الكوبون لرقم هاتفك.');
            }
        }
    }
}
