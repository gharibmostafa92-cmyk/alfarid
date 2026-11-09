<?php

declare(strict_types=1);

namespace App\Services\Orders;

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
     * @param  Order  $order  Target order to ship.
     * @param  int  $companyId  Selected shipping carrier ID.
     * @param  string|null  $awbNumber  External carrier tracking number, or null to generate internally.
     * @param  User  $actor  Staff user creating the shipment.
     * @return Shipment Created shipment manifest.
     *
     * @throws BusinessException If order state is invalid, unpaid, or already has an active AWB.
     */
    public function createForOrder(Order $order, int $companyId, ?string $awbNumber, User $actor): Shipment
    {
        return DB::transaction(function () use ($order, $companyId, $awbNumber, $actor): Shipment {
            // Pessimistic lock serializes concurrent manifest requests and prevents duplicate AWBs.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            // Active shipments cannot be duplicated; only previously failed or returned attempts permit re-shipment.
            $hasActiveShipment = $order->shipments()->active()->exists();
            if ($hasActiveShipment) {
                throw new BusinessException('توجد بوليصة نشطة لهذا الطلب بالفعل.');
            }

            // Replacement AWBs skip order state changes and stock allocations because inventory was already deducted.
            $isReplacement = $order->status === OrderStatus::Shipped
                && $order->shipments()->exists();
            if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true) && ! $isReplacement) {
                throw new BusinessException('لا يمكن إنشاء بوليصة إلا لطلب مؤكد أو قيد التجهيز.');
            }

            // Non-COD orders require confirmed payment before warehouse dispatch.
            if ($order->payment_method !== 'cod' && $order->payment_status !== 'paid') {
                throw new BusinessException('لا يمكن شحن طلب دفع مسبق لم يُدفع بعد (الحالة الحالية: '.$order->payment_status.').');
            }
            $company = ShippingCompany::query()->where('is_active', true)->find($companyId);
            if ($company === null) {
                throw new BusinessException('شركة الشحن غير موجودة أو غير نشطة.');
            }

            $rate = $this->rateFor($order, $company->id);
            $netMerchandise = (float) $order->subtotal - (float) $order->discount_amount;
            $shippingCost = $rate !== null ? $rate->effectiveCost($netMerchandise) : (float) $order->shipping_cost;

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

            // Transition order to Shipped and release reservation locks; skipped for replacement shipments.
            if (! $isReplacement) {
                $this->orders->changeStatus($order, OrderStatus::Shipped, $actor, "تم إنشاء بوليصة الشحن {$shipment->awb_number}");
            }

            return $shipment->fresh(['company', 'order']);
        });
    }

    /**
     * Find active rate matching the carrier and order's destination city zone.
     *
     * @param  Order  $order  Target order with destination city.
     * @param  int  $companyId  Target shipping company ID.
     * @return ShippingRate|null Matched shipping rate, or null if unconfigured.
     */
    private function rateFor(Order $order, int $companyId): ?ShippingRate
    {
        if ($order->city_id === null) {
            return null;
        }

        return ShippingRate::query()
            ->forCity((int) $order->city_id)
            ->where('shipping_rates.shipping_company_id', $companyId)
            ->orderBy('shipping_rates.id')
            ->select('shipping_rates.*')
            ->first();
    }

    /**
     * Generate a unique tracking identifier prefixed with the carrier code.
     *
     * @param  ShippingCompany  $company  Target shipping carrier.
     * @return string Unique AWB identifier.
     */
    private function generateAwb(ShippingCompany $company): string
    {
        do {
            $awb = Str::upper($company->code).'-'.Str::upper(Str::random(10));
        } while (Shipment::query()->where('awb_number', $awb)->exists());

        return $awb;
    }
}
