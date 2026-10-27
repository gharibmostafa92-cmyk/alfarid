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
        'occasions', 'badge_en', 'badge_ar',
    ];

    protected $casts = [
        'status' => ProductStatus::class,
        'sales_channel' => SalesChannel::class,
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'sales_count' => 'integer',
        'published_at' => 'datetime',
        'occasions' => 'array',
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
        $this->addMediaCollection('images_staging')->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection('video')->singleFile()->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
        $this->addMediaCollection('video_staging')->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
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
        // F3: التحويلات على الطابور — توليد thumb/medium المتزامن داخل
        // معاملة تقفل المنتج يحبس الصف ثوانٍ لكل ملف. الموارد تعرض
        // الأصلية حتى يجهز التحويل (hasGeneratedConversion fallback).
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('images')->queued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('images')->queued();
    }
}
