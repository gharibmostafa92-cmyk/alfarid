<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id',
        'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty',
    ];

    protected $casts = [
        'requested_qty' => 'decimal:4',
        'approved_qty' => 'decimal:4',
        'shipped_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
