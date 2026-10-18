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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

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
    // رسالة موحّدة لعدم صلاحية الكود (F-24): «غير موجود» و«موجود لكن غير
    // قابل للاستخدام» يجب ألا يتيحا تمييز وجود الكود بالتخمين
    private const NOT_REDEEMABLE = 'كود الخصم غير صحيح أو غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.';

    public function validateAndApply(Cart $cart, string $code, float $subtotal, ?Customer $customer = null): float
    {
        $coupon = $this->findRedeemable($code, $subtotal, $customer);
        $this->enforceRateLimit($coupon, 'apply');

        $cart->update(['coupon_id' => $coupon->id]);

        return $this->discountFor($coupon, $subtotal);
    }

    /**
     * حد معدّل لكل (كوبون + IP) (H3): بديل آمن عن السقف العالمي لطلبات
     * الزوار المعلّقة الذي كان مهاجم واحد يُعطّل به الحملة على كل الزوار.
     * يُستدعى عند التطبيق وعند إتمام الشراء.
     */
    public function enforceRateLimit(Coupon $coupon, string $action = 'apply'): void
    {
        $max = (int) config('store.coupon_apply_per_minute', 20);
        if ($max <= 0) {
            return;
        }

        $key = 'coupon:'.$action.':'.$coupon->id.':'.(request()->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new BusinessException(self::NOT_REDEEMABLE, [], 429);
        }
        RateLimiter::hit($key, 60);
    }

    /** يتحقق من كوبون مرتبط بسلة (عند إتمام الشراء) ويرجعه أو يرمي استثناءً. */
    public function redeemableById(int $couponId, float $subtotal, ?Customer $customer = null, ?string $guestPhone = null): Coupon
    {
        $coupon = Coupon::query()->find($couponId);
        if ($coupon === null) {
            throw new BusinessException(self::NOT_REDEEMABLE);
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

    /**
     * تسجيل استخدام فعلي عند إتمام الطلب + تحديث العداد الكلي ذرّيًا (F4).
     * قفل استشاري على (الكوبون + هوية المستخدم) يمنع سباق الحد الفردي،
     * والزيادة الشرطية على العداد الكلي تمنع تجاوز usage_limit حتى بلا
     * قفل صف الكوبون على مسار الشراء (أُزيل القفل المسبق من OrderService).
     */
    public function recordUsage(Coupon $coupon, Order $order, ?Customer $customer, float $discountAmount): CouponUsage
    {
        $guestPhoneNormalized = $customer === null && $order->guest_phone !== null
            ? Customer::normalizePhone($order->guest_phone)
            : null;

        $identity = $customer !== null
            ? 'customer:'.$customer->id
            : 'phone:'.($guestPhoneNormalized ?? 'anonymous');
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['coupon-usage:'.$coupon->id.':'.$identity]);

        // إعادة التحقق الفردي تحت القفل: استخدامان متزامنان لنفس الهوية
        // يتسلسلان فيرى الثاني استخدام الأول
        $this->assertPerIdentityAllowed($coupon, $customer, $guestPhoneNormalized);

        $incremented = Coupon::query()
            ->whereKey($coupon->id)
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->increment('used_count');
        if ($incremented === 0) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }

        return CouponUsage::create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer?->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
            // هاتف الزائر الموحد يُخزَّن مع الاستخدام لفحص الحد لاحقًا في SQL (F-04)
            'guest_phone_normalized' => $guestPhoneNormalized,
        ]);
    }

    private function findRedeemable(string $code, float $subtotal, ?Customer $customer): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();
        if ($coupon === null) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
        $this->assertRedeemable($coupon, $subtotal, $customer);

        return $coupon;
    }

    private function assertRedeemable(Coupon $coupon, float $subtotal, ?Customer $customer, ?string $guestPhone = null): void
    {
        if (! $coupon->isRedeemableNow()) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }
        // رسالة موحّدة (F20): لا تُفصح عن سبب الرفض فيُستخدم لتمييز السياسات
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new BusinessException(self::NOT_REDEEMABLE);
        }

        $guestPhoneNormalized = ($guestPhone !== null && trim($guestPhone) !== '')
            ? Customer::normalizePhone($guestPhone)
            : null;

        // (H3) أُزيل السقف العالمي لطلبات الزوار المعلّقة: كان مهاجم واحد
        // ينشئ حدًا من الطلبات الوهمية فيعطّل الحملة على كل زائر شرعي. الحماية
        // الآن: حد لكل هوية (أدناه) + حد معدّل لكل كوبون/IP + تقصير مهلة
        // المعلّق — حماية أضعف ضد تدوير الأرقام عبر IPs لكنها لا تعطّل الحملة.
        $this->assertPerIdentityAllowed($coupon, $customer, $guestPhoneNormalized);
    }

    /**
     * حد الاستخدام لكل هوية (عميل/رقم زائر) — الطلبات الملغاة لا تحتسب.
     * limit <= 0 يعني بلا حد فردي.
     */
    private function assertPerIdentityAllowed(Coupon $coupon, ?Customer $customer, ?string $guestPhoneNormalized): void
    {
        $limit = (int) $coupon->usage_limit_per_customer;
        if ($limit <= 0) {
            return;
        }

        if ($customer !== null) {
            $usedByCustomer = $coupon->usages()
                ->where('customer_id', $customer->id)
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->count();
            if ($usedByCustomer >= $limit) {
                throw new BusinessException(self::NOT_REDEEMABLE);
            }

            return;
        }

        if ($guestPhoneNormalized !== null) {
            // هوية الزائر هي رقم هاتفه الموحد — تبديل cart_token لا يتجاوز
            // الحد (H3)، والعدّ في SQL على العمود المفهرس (F-04)
            $usedByPhone = $coupon->usages()
                ->where('guest_phone_normalized', $guestPhoneNormalized)
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->count();
            if ($usedByPhone >= $limit) {
                throw new BusinessException(self::NOT_REDEEMABLE);
            }
        }
    }
}
