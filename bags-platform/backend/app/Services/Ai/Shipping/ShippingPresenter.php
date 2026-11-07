<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Models\ShippingCompany;
use App\Models\ShippingZone;

final class ShippingPresenter
{
    /** @return array<string, mixed> */
    public static function company(ShippingCompany $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'is_active' => (bool) $company->is_active,
            'tracking_url_template' => $company->tracking_url_template,
            'credentials_stored' => $company->hasApiCredentials(),
            'rates_count' => (int) ($company->rates_count ?? $company->rates()->count()),
        ];
    }

    /** @return array<string, mixed> */
    public static function zone(ShippingZone $zone): array
    {
        $zone->loadMissing('cities', 'rates');

        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'is_active' => (bool) $zone->is_active,
            'cities' => $zone->cities->map(fn ($city) => [
                'id' => $city->id,
                'name_ar' => $city->name_ar,
            ])->values()->all(),
            'rates' => $zone->rates->map(fn ($rate) => [
                'id' => $rate->id,
                'shipping_company_id' => $rate->shipping_company_id,
                'base_cost' => (float) $rate->base_cost,
                'cod_fee' => (float) $rate->cod_fee,
                'free_shipping_threshold' => $rate->free_shipping_threshold !== null ? (float) $rate->free_shipping_threshold : null,
                'estimated_days_min' => $rate->estimated_days_min,
                'estimated_days_max' => $rate->estimated_days_max,
            ])->values()->all(),
        ];
    }
}
