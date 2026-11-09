<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Wholesale\QuotationSource;
use App\Enums\Wholesale\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wholesale price quotation (RFQ) representing custom negotiation before conversion to an order.
 */
class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_number',
        'customer_id',
        'status',
        'revision',
        'valid_until',
        'priced_at',
        'priced_by',
        'source',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'customer_notes',
        'admin_notes',
        'converted_order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'source' => QuotationSource::class,
            'valid_until' => 'date',
            'priced_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }
}
