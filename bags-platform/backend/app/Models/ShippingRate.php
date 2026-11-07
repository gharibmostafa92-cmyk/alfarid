<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carrier shipping rate matrix mapping pricing, COD surcharges, and free delivery thresholds to geographic zones.
 */
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use HasFactory;

    protected $fillable = [
        'shipping_company_id',
        'shipping_zone_id',
        'base_cost',
        'free_shipping_threshold',
        'cod_fee',
        'estimated_days_min',
        'estimated_days_max',
    ];

    protected function casts(): array
    {
        return [
            'base_cost' => 'decimal:2',
            'free_shipping_threshold' => 'decimal:2',
            'cod_fee' => 'decimal:2',
            'estimated_days_min' => 'integer',
            'estimated_days_max' => 'integer',
        ];
    }

    /** @return BelongsTo<ShippingCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * Check if the specified merchandise amount qualifies for free shipping under this rate.
     */
    public function qualifiesForFreeShipping(float $merchandiseTotal): bool
    {
        return $this->free_shipping_threshold !== null
            && $merchandiseTotal >= (float) $this->free_shipping_threshold;
    }

    /**
     * Compute the effective carrier shipping cost after applying free shipping rules.
     */
    public function effectiveCost(float $merchandiseTotal): float
    {
        return $this->qualifiesForFreeShipping($merchandiseTotal) ? 0.0 : (float) $this->base_cost;
    }

    /**
     * Scope query to shipping rates matching an active shipping zone containing the city.
     *
     * @param  Builder<ShippingRate>  $query
     * @return Builder<ShippingRate>
     */
    public function scopeForCity(Builder $query, int $cityId): Builder
    {
        return $query
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_rates.shipping_zone_id')
            ->join('shipping_zone_cities', 'shipping_zone_cities.shipping_zone_id', '=', 'shipping_zones.id')
            ->where('shipping_zone_cities.city_id', $cityId)
            ->where('shipping_zones.is_active', true);
    }
}
