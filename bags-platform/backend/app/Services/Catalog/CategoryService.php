<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Services\SlugService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    /** مفتاح قفل استشاري واحد لكل تعديلات هيكل الشجرة، بيرتّبها ويمنع سباقات النقل المتزامن (H4). */
    private const TREE_LOCK_KEY = 2026101501;

    public function create(array $data, ?UploadedFile $image): Category
    {
        return DB::transaction(function () use ($data, $image) {
            $this->lockTree();

            unset($data['image']);
            $data = array_filter($data, fn ($value) => $value !== null);

            if (empty($data['slug'])) {
                $data['slug'] = SlugService::make(Category::class, $data['name_en']);
            }

            if (! empty($data['parent_id'])) {
                $parent = Category::findOrFail($data['parent_id']);
                if ($parent->depth() >= 3) {
                    throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
                }
            }

            $category = Category::create($data);

            if ($image) {
                $category->addMedia($image)->toMediaCollection('image');
            }

            return $category;
        });
    }

    public function update(Category $category, array $data, ?UploadedFile $image): Category
    {
        return DB::transaction(function () use ($category, $data, $image) {
            $this->lockTree();

            $removeImage = (bool) ($data['remove_image'] ?? false);
            unset($data['image'], $data['remove_image']);

            if (array_key_exists('parent_id', $data)) {
                $newParentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];
                if ($newParentId !== $category->parent_id) {
                    $this->assertValidParent($category, $newParentId);
                }
            }

            // تعطيل قسم (أو شجرته الفرعية) فيها منتجات منشورة ممنوع: هتكسر قواعد نشرها (H1)
            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $category->is_active) {
                $this->assertNoPublishedProducts($category);
            }

            if (array_key_exists('slug', $data) && empty($data['slug'])) {
                $data['slug'] = SlugService::make(Category::class, $data['name_en'] ?? $category->name_en, $category->id);
            }

            foreach (['sort_order', 'is_active'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] === null) {
                    unset($data[$field]);
                }
            }

            $category->update($data);

            if ($removeImage) {
                $category->clearMediaCollection('image');
            }

            if ($image) {
                $category->addMedia($image)->toMediaCollection('image');
            }

            return $category;
        });
    }

    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $this->lockTree();

            if ($category->children()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم يحتوي على أقسام فرعية');
            }

            if ($category->products()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم مرتبط بمنتجات');
            }

            $category->delete();
        });
    }

    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items): void {
            $this->lockTree();

            foreach ($items as $item) {
                $category = Category::findOrFail($item['id']);
                $parentId = isset($item['parent_id']) ? (int) $item['parent_id'] : null;
                $this->assertValidParent($category, $parentId);
                $category->update(['parent_id' => $parentId, 'sort_order' => $item['sort_order']]);
            }
        });
    }

    public function tree(bool $onlyActive = false): Collection
    {
        $all = Category::query()->with('media')->withCount('products')
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->get();
        $grouped = $all->groupBy('parent_id');
        $build = function ($parentId) use (&$build, $grouped) {
            return ($grouped[$parentId ?? ''] ?? collect())->map(function ($cat) use ($build) {
                $cat->setRelation('children', $build($cat->id));

                return $cat;
            })->values();
        };

        return $build(null);
    }

    public function assertValidParent(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $category->id) {
            throw new BusinessException('لا يمكن أن يكون القسم أبًا لنفسه');
        }

        if (in_array($parentId, $category->descendantIds(), true)) {
            throw new BusinessException('لا يمكن نقل القسم إلى أحد أقسامه الفرعية');
        }

        $parent = Category::findOrFail($parentId);

        if ($parent->depth() + $this->subtreeHeight($category) > 3) {
            throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
        }
    }

    private function assertNoPublishedProducts(Category $category): void
    {
        $ids = array_merge([$category->id], $category->descendantIds());
        $hasPublished = Product::whereIn('category_id', $ids)
            ->where('status', ProductStatus::Published)
            ->exists();
        if ($hasPublished) {
            throw new BusinessException('لا يمكن تعطيل قسم يحتوي على منتجات منشورة');
        }
    }

    private function lockTree(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::TREE_LOCK_KEY]);
        }
    }

    private function subtreeHeight(Category $category, int $guard = 0): int
    {
        // أقصى عمق مشروع 3؛ أي تجاوز كبير معناه بيانات فاسدة (دورة) فنوقف بدل ما نلف للأبد
        if ($guard > 10) {
            throw new \RuntimeException('توجد دورة في شجرة الأقسام');
        }

        $height = 1;
        foreach ($category->children as $child) {
            $height = max($height, 1 + $this->subtreeHeight($child, $guard + 1));
        }

        return $height;
    }
}
