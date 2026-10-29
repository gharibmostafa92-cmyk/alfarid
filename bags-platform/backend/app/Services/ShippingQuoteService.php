<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\City;
use App\Models\ShippingRate;

/**
 * Computes customer shipping quotes and COD surcharges.
 *
 * Resolves rates from active carriers matching the destination city zone.
 * Waives shipping cost when the merchandise total qualifies for free shipping,
 * and falls back to the default city flat rate when no specific carrier rate matches.
 */
class ShippingQuoteService
{
    /**
     * Calculate shipping rate and cash-on-delivery fees for an order destination.
     *
     * @param  int  $cityId
     * @param  float  $merchandiseTotal
     * @param  bool  $cod
     * @return array{cost: float, cod_fee: float, rate: ShippingRate|null}
     */
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
