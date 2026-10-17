<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\City;
use App\Models\ShippingRate;

/**
 * تسعيرة الشحن التي يدفعها العميل (مصدر موحد مع تكلفة البوليصة — M12):
 * أول شركة نشطة (بترتيب id) لها سعر منطبق على المدينة؛ عند بلوغ حد الشحن
 * المجاني تكون التكلفة صفرًا، ورسوم الدفع عند الاستلام من نفس السعر.
 * بلا أي سعر منطبق: رجوع لسعر المدينة الافتراضي بلا رسوم COD.
 */
class ShippingQuoteService
{
    /** @return array{cost: float, cod_fee: float, rate: ShippingRate|null} */
    public function quoteForCity(int $cityId, float $merchandiseTotal, bool $cod): array
    {
        $rate = ShippingRate::query()
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_rates.shipping_zone_id')
            ->join('shipping_zone_cities', 'shipping_zone_cities.shipping_zone_id', '=', 'shipping_zones.id')
            ->join('shipping_companies', 'shipping_companies.id', '=', 'shipping_rates.shipping_company_id')
            ->where('shipping_zone_cities.city_id', $cityId)
            ->where('shipping_zones.is_active', true)
            ->where('shipping_companies.is_active', true)
            ->orderBy('shipping_companies.id')
            ->orderBy('shipping_rates.id')
            ->select('shipping_rates.*')
            ->first();

        if ($rate === null) {
            $city = City::query()->find($cityId);

            return ['cost' => (float) ($city?->shipping_cost ?? 0), 'cod_fee' => 0.0, 'rate' => null];
        }

        $free = $rate->free_shipping_threshold !== null
            && $merchandiseTotal >= (float) $rate->free_shipping_threshold;

        return [
            'cost' => $free ? 0.0 : (float) $rate->base_cost,
            'cod_fee' => $cod ? (float) $rate->cod_fee : 0.0,
            'rate' => $rate,
        ];
    }
}
