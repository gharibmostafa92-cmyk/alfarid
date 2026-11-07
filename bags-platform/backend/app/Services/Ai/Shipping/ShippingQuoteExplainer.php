<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Models\City;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Services\ShippingQuoteService;
use Illuminate\Support\Collection;

/**
 * Explain ShippingQuoteService calculation outcomes and simulate in-memory override scenarios.
 */
final class ShippingQuoteExplainer
{
    public function __construct(private ShippingQuoteService $quotes) {}

    /**
     * @param  array{rate_id?:int,base_cost?:float|null,cod_fee?:float|null,free_shipping_threshold?:float|null}|null  $override
     * @return array<string, mixed>
     */
    public function explain(int $cityId, float $merchandise, bool $cod, ?array $override = null): array
    {
        $city = City::query()->find($cityId);
        $matched = $this->matchedRates($cityId);
        $winner = $matched->first();
        $engine = $this->quotes->quoteForCity($cityId, $merchandise, $cod);
        $rate = $winner ? $this->cloneRate($winner) : null;
        $applied = false;
        if ($override !== null && $rate !== null && (int) ($override['rate_id'] ?? $rate->id) === $rate->id) {
            foreach (['base_cost', 'cod_fee', 'free_shipping_threshold'] as $field) {
                if (array_key_exists($field, $override)) {
                    $rate->{$field} = $override[$field];
                    $applied = true;
                }
            }
        }
        $priced = $this->price($rate, $city, $merchandise, $cod);
        $numbers = $override === null ? [
            'cost' => (float) $engine['cost'],
            'cod_fee' => (float) $engine['cod_fee'],
        ] : [
            'cost' => $priced['cost'],
            'cod_fee' => $priced['cod_fee'],
        ];

        return [
            'city' => $city ? ['id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en] : ['id' => $cityId],
            'merchandise_total' => $merchandise,
            'payment_method' => $cod ? 'cod' : 'other',
            'cost' => $numbers['cost'],
            'cod_fee' => $numbers['cod_fee'],
            'currency' => 'EGP',
            'source' => $priced['source'],
            'threshold_applied' => $priced['threshold_applied'],
            'cod_fee_waived_by_threshold' => false,
            'winner_rule' => 'first_active_company_by_id',
            'override_applied' => $applied,
            'rate' => $winner ? $this->ratePayload($winner, $rate) : null,
            'also_matched' => $matched->slice(1)->map(fn (ShippingRate $row) => [
                'rate_id' => $row->id,
                'company_id' => $row->shipping_company_id,
                'company_name' => $row->company?->name,
                'zone_id' => $row->shipping_zone_id,
                'zone_name' => $row->zone?->name,
                'base_cost' => (float) $row->base_cost,
            ])->values()->all(),
            'numbers_from' => $override === null ? 'shipping_quote_service' : 'in_memory_same_rules',
        ];
    }

    /** @return Collection<int, ShippingRate> */
    public function matchedRates(int $cityId): Collection
    {
        $ids = ShippingRate::query()
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_rates.shipping_zone_id')
            ->join('shipping_zone_cities', 'shipping_zone_cities.shipping_zone_id', '=', 'shipping_zones.id')
            ->join('shipping_companies', 'shipping_companies.id', '=', 'shipping_rates.shipping_company_id')
            ->where('shipping_zone_cities.city_id', $cityId)
            ->where('shipping_zones.is_active', true)
            ->where('shipping_companies.is_active', true)
            ->orderBy('shipping_companies.id')
            ->orderBy('shipping_rates.id')
            ->pluck('shipping_rates.id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $rows = ShippingRate::query()->with(['company:id,name,code,is_active', 'zone:id,name,is_active'])->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $rows->get($id))->filter()->values();
    }

    private function cloneRate(ShippingRate $rate): ShippingRate
    {
        $copy = $rate->replicate();
        $copy->id = $rate->id;
        $copy->setRelation('company', $rate->company);
        $copy->setRelation('zone', $rate->zone);

        return $copy;
    }

    /** @return array{cost: float, cod_fee: float, source: string, threshold_applied: bool} */
    private function price(?ShippingRate $rate, ?City $city, float $merchandise, bool $cod): array
    {
        if ($rate === null) {
            return [
                'cost' => (float) ($city?->shipping_cost ?? 0),
                'cod_fee' => 0.0,
                'source' => 'city_fallback',
                'threshold_applied' => false,
            ];
        }
        $free = $rate->free_shipping_threshold !== null && $merchandise >= (float) $rate->free_shipping_threshold;

        return [
            'cost' => $free ? 0.0 : (float) $rate->base_cost,
            'cod_fee' => $cod ? (float) $rate->cod_fee : 0.0,
            'source' => 'rate',
            'threshold_applied' => $free,
        ];
    }

    /** @return array<string, mixed> */
    private function ratePayload(ShippingRate $stored, ShippingRate $priced): array
    {
        return [
            'id' => $stored->id,
            'company_id' => $stored->shipping_company_id,
            'company_name' => $stored->company?->name,
            'company_code' => $stored->company?->code,
            'zone_id' => $stored->shipping_zone_id,
            'zone_name' => $stored->zone?->name,
            'base_cost' => (float) $priced->base_cost,
            'free_shipping_threshold' => $priced->free_shipping_threshold !== null ? (float) $priced->free_shipping_threshold : null,
            'cod_fee' => (float) $priced->cod_fee,
            'estimated_days_min' => $stored->estimated_days_min,
            'estimated_days_max' => $stored->estimated_days_max,
            'credentials_stored' => $stored->company instanceof ShippingCompany && $stored->company->hasApiCredentials(),
        ];
    }
}
