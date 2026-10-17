<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingCompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            // بيانات اعتماد واجهة الشركة — مشفرة في قاعدة البيانات
            'api_credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
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
