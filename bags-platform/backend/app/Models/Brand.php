<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Brand extends Model implements HasMedia
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = ['name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile()->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    // L5: brand tiles render small thumbs — generate them once instead of
    // serving up-to-6000px originals in storefront tiles.
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('logo');
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('logo');
    }
}
