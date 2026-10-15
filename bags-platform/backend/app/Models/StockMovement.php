<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * سجل دفتر أستاذ المخزون: تاريخي وغير قابل للتعديل أو الحذف نهائيًا (Immutable Ledger).
 */
class StockMovement extends Model
{
    /** لا يوجد updated_at: السجل لا يتغير بعد كتابته. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'reference_number', 'product_variant_id', 'branch_id', 'location_id',
        'type', 'affected_bucket', 'quantity', 'balance_before', 'balance_after',
        'unit_cost', 'reference_type', 'reference_id', 'created_by', 'notes',
    ];

    protected $casts = [
        'type' => StockMovementType::class,
        'affected_bucket' => StockBucket::class,
        'quantity' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('سجلات دفتر المخزون لا تُعدَّل'));
        static::deleting(fn () => throw new LogicException('سجلات دفتر المخزون لا تُحذف'));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
