<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Services\SlugService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function create(array $data, ?UploadedFile $image): Category
    {
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
    }

    public function update(Category $category, array $data, ?UploadedFile $image): Category
    {
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);

        if (array_key_exists('parent_id', $data)) {
            $newParentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];
            if ($newParentId !== $category->parent_id) {
                $this->assertValidParent($category, $newParentId);
            }
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
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw new BusinessException('لا يمكن حذف قسم يحتوي على أقسام فرعية');
        }

        if ($category->products()->exists()) {
            throw new BusinessException('لا يمكن حذف قسم مرتبط بمنتجات');
        }

        $category->delete();
    }

    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items): void {
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

    private function subtreeHeight(Category $category): int
    {
        $height = 1;
        foreach ($category->children as $child) {
            $height = max($height, 1 + $this->subtreeHeight($child));
        }

        return $height;
    }
}
