<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** رابط تتبع عام من قالب الشركة إن وُجد. */
    public function trackingUrl(): ?string
    {
        $template = $this->company?->tracking_url_template;
        if ($template === null || $template === '') {
            return null;
        }

        // رقم البوليصة قد يحمل محارف خاصة من إدخال الأدمن — تُرمَّز (L4)
        return str_replace('{awb}', rawurlencode((string) $this->awb_number), $template);
    }
}
