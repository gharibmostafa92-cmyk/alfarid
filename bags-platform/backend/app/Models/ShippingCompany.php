<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingCompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Logistics service provider or courier integrated for order delivery.
 */
class ShippingCompany extends Model
{
    /** @use HasFactory<ShippingCompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'api_credentials',
        'tracking_url_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Carrier API credentials stored encrypted at rest.
            'api_credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether credentials are stored, without decrypting them.
     * A rotated APP_KEY must not turn the company index into a 500.
     */
    public function hasApiCredentials(): bool
    {
        $raw = $this->getRawOriginal('api_credentials');

        return is_string($raw) && $raw !== '';
    }

    /** @return HasMany<ShippingRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
