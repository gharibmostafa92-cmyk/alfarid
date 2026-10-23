<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Category extends Model implements HasMedia
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = ['parent_id', 'name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

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

    /** المنتجات المنشورة فقط — أساس عدّادات أقسام المتجر. */
    public function activeProducts(): HasMany
    {
        return $this->hasMany(Product::class)->where('status', ProductStatus::Published)
            ->whereIn('sales_channel', [SalesChannel::Retail, SalesChannel::Both]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk('r2_public')->storeConversionsOnDisk('r2_public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    // L5: category tiles render small thumbs — generate them once instead of
    // serving up-to-6000px originals in storefront tiles.
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('image');
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('image');
    }

    // بيرجّع IDs كل الأقسام اللي تحته على أي مستوى — مجموعة visited بتمنع اللف اللانهائي لو فيه دورة في البيانات
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
