<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksEditVersion;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductVariant extends Model implements HasMedia
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes, TracksEditVersion;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'color_name_ar', 'color_name_en', 'color_hex', 'size', 'capacity',
        'cost_price', 'last_cost_price', 'retail_price', 'wholesale_price', 'compare_at_price', 'reorder_level', 'weight_kg', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:4', 'last_cost_price' => 'decimal:4', 'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2', 'compare_at_price' => 'decimal:2',
        'weight_kg' => 'decimal:3', 'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $variant): void {
            CartItem::query()->where('product_variant_id', $variant->id)->delete();
        });
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Compatibility with the catalog's existing editable retail price. */
    public function getBasePriceAttribute(): string
    {
        return (string) ($this->retail_price ?? 0);
    }

    // صور خاصة باللون ده
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('r2_public')->storeConversionsOnDisk('r2_public')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection('images_staging')->useDisk('r2_public')->storeConversionsOnDisk('r2_public')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // F3: queued like Product — no synchronous conversion inside locks.
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')->performOnCollections('images')->queued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')->performOnCollections('images')->queued();
    }
}
