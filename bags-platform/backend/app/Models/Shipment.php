<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Orders\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carrier tracking and dispatch manifest for fulfilled customer orders.
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'shipping_company_id',
        'awb_number',
        'status',
        'label_url',
        'shipping_cost',
        'cod_amount',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipping_cost' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'raw_response' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ShippingCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
    }

    /**
     * Scope a query to active shipments that have not terminated in failure or return.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [ShipmentStatus::Failed->value, ShipmentStatus::Returned->value]);
    }

    /** Public tracking URL built from carrier template if configured. */
    public function trackingUrl(): ?string
    {
        $template = $this->company?->tracking_url_template;
        if ($template === null || $template === '') {
            return null;
        }

        // URL-encode AWB numbers to safely handle special characters in tracking URL templates.
        return str_replace('{awb}', rawurlencode((string) $this->awb_number), $template);
    }
}
