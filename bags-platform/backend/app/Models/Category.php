<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\Concerns\TracksEditVersion;
use App\Support\CatalogMedia;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Hierarchical category model organizing products into multi-level classification trees.
 */
class Category extends Model implements HasMedia
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes, TracksEditVersion;

    protected $fillable = ['parent_id', 'name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'lock_version' => 'integer'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Active retail products for storefront category counters. */
    public function activeProducts(): HasMany
    {
        return $this->hasMany(Product::class)->publishedForRetail();
    }

    /**
     * Scope to categories that are active and whose ancestor path (up to 3 levels) is active.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActiveHierarchy(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($path) => $path->whereNull('parent_id')
                ->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)
                    ->where(fn ($path) => $path->whereNull('parent_id')
                        ->orWhereHas('parent', fn ($ancestor) => $ancestor->where('is_active', true)->whereNull('parent_id')))));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk(CatalogMedia::disk())->storeConversionsOnDisk(CatalogMedia::disk())
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    // Generate webp thumbnails and medium variants to avoid serving original master images in UI tiles.
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('image');
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('image');
    }

    /**
     * Returns all recursive descendant category IDs using cycle detection.
     *
     * @return list<int> Array of descendant category IDs
     */
    public function descendantIds(): array
    {
        $ids = [];
        $seen = [$this->id => true];
        $queue = [$this->id];
        while ($queue) {
            $children = Category::whereIn('parent_id', $queue)->pluck('id')->all();
            $queue = [];
            foreach ($children as $childId) {
                if (! isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * Calculates the category depth from root (root = 1), detecting circular parent references.
     *
     * @return int 1-based hierarchy depth
     *
     * @throws \RuntimeException If a cycle is detected in the parent chain
     */
    public function depth(): int
    {
        $depth = 1;
        $seen = [$this->id => true];
        $p = $this->parent;
        while ($p) {
            if (isset($seen[$p->id])) {
                throw new \RuntimeException('توجد دورة في شجرة الأقسام');
            }
            $seen[$p->id] = true;
            $depth++;
            $p = $p->parent;
        }

        return $depth;
    }
}
