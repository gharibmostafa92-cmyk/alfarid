<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Concerns\TracksEditVersion;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes, TracksEditVersion;

    protected $fillable = [
        'code', 'category_id', 'brand_id', 'supplier_id', 'name_ar', 'name_en', 'slug',
        'description_ar', 'description_en', 'material_ar', 'material_en',
        'length_cm', 'width_cm', 'height_cm', 'weight_kg', 'warranty_months', 'warranty_details',
        'country_of_origin', 'status', 'sales_channel', 'is_featured', 'sales_count', 'published_at', 'created_by', 'create_key', 'create_request_hash',
    ];

    protected $casts = [
        'status' => ProductStatus::class,
        'sales_channel' => SalesChannel::class,
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'sales_count' => 'integer',
        'published_at' => 'datetime',
        'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2', 'height_cm' => 'decimal:2',
        'weight_kg' => 'decimal:3',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        // chaperone: كل variant متحمل من العلاقة دي بيبقى عارف منتجه من غير lazy load (N+1)
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->chaperone();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection('video')->singleFile()->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
    }

    public function catalogImages(): Collection
    {
        $variants = $this->relationLoaded('variants')
            ? $this->variants
            : $this->variants()->where('is_active', true)->with('media')->get();

        return $this->getMedia('images')->concat($variants
            ->filter(fn ($variant) => $variant->is_active && ! $variant->trashed())
            ->flatMap(fn ($variant) => $variant->getMedia('images')))->values();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // H2 keeps conversions synchronous but lock-free: each upload runs in its
        // own short transaction with no product lock held while R2 I/O renders.
        // Queuing them would break per-file atomicity (a failed conversion must
        // roll back its own file) that the media pipeline and tests rely on.
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('images')->nonQueued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('images')->nonQueued();
    }
}
