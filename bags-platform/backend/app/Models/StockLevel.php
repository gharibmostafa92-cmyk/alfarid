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

    /**
     * L6: rows currently in an alert state — mirrors the two
     * StockLowDetected triggers in InventoryService::recordMovement:
     * a set row threshold compares against the row, while an unset row
     * threshold falls back to the variant threshold compared against the
     * branch-wide total (not the single row).
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeWhereLowStock(Builder $query): Builder
    {
        return $query
            ->join('product_variants as pv_low', 'pv_low.id', '=', 'stock_levels.product_variant_id')
            ->leftJoinSub(
                self::query()->selectRaw('branch_id, product_variant_id, COALESCE(SUM(on_hand - reserved - damaged), 0) AS branch_available')
                    ->groupBy('branch_id', 'product_variant_id'),
                'branch_totals',
                fn ($join) => $join
                    ->on('branch_totals.branch_id', '=', 'stock_levels.branch_id')
                    ->on('branch_totals.product_variant_id', '=', 'stock_levels.product_variant_id')
            )
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $row) => $row
                    ->where('stock_levels.reorder_level', '>', 0)
                    ->whereRaw('stock_levels.available <= stock_levels.reorder_level'))
                ->orWhere(fn (Builder $variant) => $variant
                    ->whereRaw('COALESCE(stock_levels.reorder_level, 0) = 0')
                    ->where('pv_low.reorder_level', '>', 0)
                    ->whereRaw('COALESCE(branch_totals.branch_available, 0) <= pv_low.reorder_level')))
            ->select('stock_levels.*');
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
