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
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages order shipping manifests (AWBs) and carrier rate assignment.
 *
 * Generates tracking identifiers internally or registers carrier AWBs,
 * calculates carrier shipping costs based on destination city zones and free shipping thresholds,
 * and coordinates order status transitions with inventory dispatch.
 */
class ShipmentService
{
    public function __construct(protected OrderService $orders) {}

    /**
     * Create a shipment manifest for an order, assign shipping fees, and trigger dispatch state.
     *
     * @param  Order  $order
     * @param  int  $companyId
     * @param  string|null  $awbNumber
     * @param  User  $actor
     * @return Shipment
     *
     * @throws BusinessException
     */
    public function createForOrder(Order $order, int $companyId, ?string $awbNumber, User $actor): Shipment
    {
        return DB::transaction(function () use ($order, $companyId, $awbNumber, $actor): Shipment {
            // Lock order to prevent race conditions and duplicate shipments
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $hasActiveShipmentEarly = $order->shipments()
                ->whereNotIn('status', [ShipmentStatus::Failed->value, ShipmentStatus::Returned->value])
                ->exists();
            // Allow replacement AWB issuance without re-deducting stock if all prior attempts failed or returned
            $isReplacement = $order->status === OrderStatus::Shipped
                && ! $hasActiveShipmentEarly
                && $order->shipments()->exists();
            if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true) && ! $isReplacement) {
                throw new BusinessException('لا يمكن إنشاء بوليصة إلا لطلب مؤكد أو قيد التجهيز.');
            }
            // Non-COD orders require confirmed payment before warehouse dispatch
            if ($order->payment_method !== 'cod' && $order->payment_status !== 'paid') {
                throw new BusinessException('لا يمكن شحن طلب دفع مسبق لم يُدفع بعد (الحالة الحالية: '.$order->payment_status.').');
            }
            if ($hasActiveShipmentEarly) {
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

            // Transition order status to Shipped and release stock allocations (bypassed for replacement shipments)
            if (! $isReplacement) {
                $this->orders->changeStatus($order, OrderStatus::Shipped, $actor, "تم إنشاء بوليصة الشحن {$shipment->awb_number}");
            }

            return $shipment->fresh(['company', 'order']);
        });
    }

    /**
     * Find active rate matching the carrier and order's destination city zone.
     *
     * Joins directly with zone cities to avoid ambiguous matching if a city is mapped to multiple zones.
     */
    private function rateFor(Order $order, int $companyId): ?ShippingRate
    {
        if ($order->city_id === null) {
            return null;
        }

        return ShippingRate::query()
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_rates.shipping_zone_id')
            ->join('shipping_zone_cities', 'shipping_zone_cities.shipping_zone_id', '=', 'shipping_zones.id')
            ->where('shipping_rates.shipping_company_id', $companyId)
            ->where('shipping_zone_cities.city_id', $order->city_id)
            ->where('shipping_zones.is_active', true)
            ->orderBy('shipping_rates.id')
            ->select('shipping_rates.*')
            ->first();
    }

    /**
     * Generate a unique tracking number prefixed with the carrier code.
     */
    private function generateAwb(ShippingCompany $company): string
    {
        do {
            $awb = Str::upper($company->code).'-'.Str::upper(Str::random(10));
        } while (Shipment::query()->where('awb_number', $awb)->exists());

        return $awb;
    }
}
