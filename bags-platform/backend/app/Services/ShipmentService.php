<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * إنشاء بوالص الشحن للطلبات.
 * لا توجد بوابة شركة شحن مربوطة بعد: رقم البوليصة يُولّد داخليًا
 * (أو يُمرّر جاهزًا من الأدمن)، وتكلفة البوليصة من جدول أسعار الشحن
 * (شركة × منطقة مدينة الطلب) مع احترام حد الشحن المجاني.
 */
class ShipmentService
{
    public function __construct(protected OrderService $orders) {}

    public function createForOrder(Order $order, int $companyId, ?string $awbNumber, User $actor): Shipment
    {
        return DB::transaction(function () use ($order, $companyId, $awbNumber, $actor): Shipment {
            // قفل الطلب والتحقق من حالته ومن عدم وجود بوليصة نشطة —
            // نقرة مزدوجة لا تنتج بوليصتين ولا بيعًا مكررًا (H1)
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new BusinessException('لا يمكن إنشاء بوليصة إلا لطلب مؤكد أو قيد التجهيز.');
            }
            $hasActiveShipment = $order->shipments()
                ->whereNotIn('status', [ShipmentStatus::Failed->value, ShipmentStatus::Returned->value])
                ->exists();
            if ($hasActiveShipment) {
                throw new BusinessException('توجد بوليصة نشطة لهذا الطلب بالفعل.');
            }

            $company = ShippingCompany::query()->where('is_active', true)->find($companyId);
            if ($company === null) {
                throw new BusinessException('شركة الشحن غير موجودة أو غير نشطة.');
            }

            $rate = $this->rateFor($order, $company->id);
            $shippingCost = $rate !== null ? (float) $rate->base_cost : (float) $order->shipping_cost;
            if ($rate !== null && $rate->free_shipping_threshold !== null
                && ((float) $order->subtotal - (float) $order->discount_amount) >= (float) $rate->free_shipping_threshold) {
                $shippingCost = 0.0;
            }

            $shipment = Shipment::create([
                'order_id' => $order->id,
                'shipping_company_id' => $company->id,
                'awb_number' => $awbNumber ?? $this->generateAwb($company),
                'status' => ShipmentStatus::Created,
                'label_url' => null,
                'shipping_cost' => $shippingCost,
                'cod_amount' => $order->payment_method === 'cod' ? (float) $order->grand_total : 0,
                'raw_response' => ['source' => 'internal'],
            ]);

            // الشحن الفعلي للطلب (يفك الحجز ويخصم البيع) عبر بوابة الحالة
            $this->orders->changeStatus($order, OrderStatus::Shipped, $actor, "تم إنشاء بوليصة الشحن {$shipment->awb_number}");

            return $shipment->fresh(['company', 'order']);
        });
    }

    /** سعر الشركة لمنطقة مدينة الطلب إن وُجد. */
    private function rateFor(Order $order, int $companyId): ?ShippingRate
    {
        if ($order->city_id === null) {
            return null;
        }
        $zone = ShippingZone::query()
            ->where('is_active', true)
            ->whereHas('cities', fn ($q) => $q->where('cities.id', $order->city_id))
            ->first();
        if ($zone === null) {
            return null;
        }

        return ShippingRate::query()
            ->where('shipping_company_id', $companyId)
            ->where('shipping_zone_id', $zone->id)
            ->first();
    }

    private function generateAwb(ShippingCompany $company): string
    {
        do {
            $awb = Str::upper($company->code).'-'.Str::upper(Str::random(10));
        } while (Shipment::query()->where('awb_number', $awb)->exists());

        return $awb;
    }
}
