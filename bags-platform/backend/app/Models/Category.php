<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
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
