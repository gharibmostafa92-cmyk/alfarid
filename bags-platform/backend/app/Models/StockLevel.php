<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد مخزون حالي لمتغير في فرع/موقع. ممنوع التعديل المباشر:
 * أي تغيير بيتم حصرًا عن طريق InventoryService::recordMovement (قاعدة المرحلة 3 الصارمة).
 */
class StockLevel extends Model
{
    protected $fillable = [
        'product_variant_id', 'branch_id', 'location_id',
        'reorder_level',
    ];

    protected $casts = [
        'on_hand' => 'decimal:4',
        'reserved' => 'decimal:4',
        'damaged' => 'decimal:4',
        'in_transit' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'available' => 'decimal:4',
    ];

    /** المتاح للبيع = الفعلي − المحجوز − التالف (نفس معادلة العمود المولّد في القاعدة). */
    public function getAvailableAttribute(): float
    {
        return round((float) $this->on_hand - (float) $this->reserved - (float) $this->damaged, 4);
    }

    /**
     * نطاق للأرصدة غير الصفرية في أي وعاء مخزني (فعلي، محجوز، تالف، قيد النقل).
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeHasNonZeroBalance(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('on_hand', '<>', 0)
            ->orWhere('reserved', '<>', 0)
            ->orWhere('damaged', '<>', 0)
            ->orWhere('in_transit', '<>', 0));
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
}
