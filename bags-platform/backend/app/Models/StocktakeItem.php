<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product variant line item within a stocktake session comparing expected and counted quantities.
 */
class StocktakeItem extends Model
{
    protected $fillable = [
        'stocktake_id', 'product_variant_id', 'system_qty', 'counted_qty', 'snapshot_movement_id',
    ];

    protected $casts = [
        'snapshot_movement_id' => 'integer',
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
        'difference' => 'decimal:4',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
