<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual barcode scan record captured during a physical stocktake.
 */
class StocktakeScan extends Model
{
    protected $fillable = [
        'stocktake_id', 'scan_id', 'product_variant_id', 'qty', 'created_by',
    ];

    protected $casts = [
        'qty' => 'float',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
