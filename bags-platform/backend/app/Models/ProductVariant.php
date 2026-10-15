<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductVariant extends Model implements HasMedia
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'color_name_ar', 'color_name_en', 'color_hex', 'size', 'capacity',
        'cost_price', 'retail_price', 'wholesale_price', 'reorder_level', 'weight_kg', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2', 'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2',
        'weight_kg' => 'decimal:3', 'is_active' => 'boolean',
    ];

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
        $this->addMediaCollection('images')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')->nonQueued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')->nonQueued();
    }
}
