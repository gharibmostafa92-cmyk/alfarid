<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
