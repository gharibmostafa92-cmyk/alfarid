<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Services\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function create(array $data, ?UploadedFile $image): Category
    {
        // M1: retry once on auto-slug races; M5: shared tree lock + FOR SHARE on
        // the parent — creating a leaf must not take the exclusive tree lock.
        $autoSlug = empty($data['slug'] ?? null);
        for ($attempt = 0; ; $attempt++) {
            try {
                $category = DB::transaction(function () use ($data) {
                    CatalogLock::tree(shared: true);

                    unset($data['image']);
                    $data = array_filter($data, fn ($value) => $value !== null);

                    if (empty($data['slug'])) {
                        $data['slug'] = SlugService::make(Category::class, $data['name_en']);
                    }

                    if (! empty($data['parent_id'])) {
                        $parent = Category::whereKey($data['parent_id'])->sharedLock()->firstOrFail();
                        if ($parent->depth() >= 3) {
                            throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
                        }
                    }

                    return Category::create($data);
                });

                // F2: the R2 upload (and any sync conversion) runs outside the
                // tree lock, in its own short transaction — network I/O must
                // not hold an advisory lock that serializes all catalog writes.
                if ($image) {
                    $this->attachImage($category, $image);
                }

                return $category;
            } catch (UniqueConstraintViolationException $e) {
                if ($autoSlug && $attempt === 0) {
                    $data['slug'] = SlugService::make(Category::class, $data['name_en']);

                    continue;
                }
                throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
            }
        }
    }

    public function update(Category $category, array $data, ?UploadedFile $image): Category
    {
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);
        if (isset($data['parent_id'])) {
            $data['parent_id'] = (int) $data['parent_id'];
        }

        foreach (['sort_order', 'is_active'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                unset($data[$field]);
            }
        }
        $apply = function (bool $exclusive) use ($category, $data): ?Category {
            return DB::transaction(function () use ($category, $data, $exclusive) {
                CatalogLock::tree(shared: ! $exclusive);
                $category = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
                $parentChanged = array_key_exists('parent_id', $data) && $data['parent_id'] !== $category->parent_id;
                $activeChanged = isset($data['is_active']) && (bool) $data['is_active'] !== $category->is_active;
                if (($parentChanged || $activeChanged) && ! $exclusive) {
                    return null;
                }
                if ($parentChanged || $activeChanged) {
                    $nodes = CategoryHierarchy::snapshot();
                    $nodes[$category->id] = array_replace($nodes[$category->id], array_intersect_key($data, array_flip(['parent_id', 'is_active'])));
                    CategoryHierarchy::assertValid($nodes);
                    CategoryHierarchy::assertPublishedPathsActive($nodes);
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

                return $category;
            });
        };

        // F8: a concurrent update can win the slug between validation and the
        // write — surface it as 409, not 500.
        try {
            $updated = $apply(false) ?? $apply(true);
        } catch (UniqueConstraintViolationException $e) {
            throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
        }

        // F2: image changes run outside the tree lock (see create()).
        if ($image) {
            $this->attachImage($updated, $image);
        } elseif ($removeImage) {
            DB::transaction(fn () => $updated->clearMediaCollection('image'));
        }

        return $updated;
    }

    /**
     * F2: attach the image in its own short transaction with no tree lock.
     * The image is optional, so a failed upload keeps the saved row and
     * reports a clear message instead of rolling back the data.
     */
    private function attachImage(Category $category, UploadedFile $image): void
    {
        try {
            DB::transaction(fn () => $category->addMedia($image)->toMediaCollection('image'));
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('تم حفظ القسم لكن فشل رفع الصورة؛ أعد رفعها من صفحة التعديل.', ['category_id' => [$category->id]], 422, 'category_image_failed');
        }
    }

    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $this->lockTree();
            $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();

            if ($locked->children()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم يحتوي على أقسام فرعية');
            }

            if ($locked->products()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }

    public function reorder(array $items): void
    {
        // Retry with an exclusive tree lock only if the fresh snapshot contains a move.
        // Never upgrade a shared advisory lock while retaining it.
        $apply = function (bool $exclusive) use ($items): bool {
            return DB::transaction(function () use ($items, $exclusive): bool {
                CatalogLock::tree(shared: ! $exclusive);
                $categories = Category::query()->get()->keyBy('id');
                $nodes = $categories->toArray();
                $moved = false;
                foreach ($items as $item) {
                    if (! array_key_exists('parent_id', $item)) {
                        throw new BusinessException('يجب إرسال parent_id لكل عنصر في الترتيب');
                    }
                    $category = $categories->get($item['id']);
                    if (! $category) {
                        throw new BusinessException('القسم غير موجود', ['items' => ['أحد الأقسام لم يعد متاحًا']]);
                    }
                    $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];
                    $moved = $moved || $parentId !== $category->parent_id;
                    $nodes[$category->id]['parent_id'] = $parentId;
                }
                if ($moved && ! $exclusive) {
                    return false;
                }
                if ($moved) {
                    CategoryHierarchy::assertValid($nodes);
                    CategoryHierarchy::assertPublishedPathsActive($nodes);
                }
                // M11: apply row updates in id order so concurrent order-only
                // requests acquire row locks in the same order (no deadlock).
                $ordered = collect($items)->sortBy('id')->values()->all();
                foreach ($ordered as $item) {
                    $categories->get($item['id'])->update(['parent_id' => $nodes[$item['id']]['parent_id'], 'sort_order' => $item['sort_order']]);
                }

                return true;
            });
        };
        if (! $apply(false)) {
            $apply(true);
        }
    }

    public function tree(bool $onlyActive = false): Collection
    {
        $all = Category::query()->with('media')->withCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()])
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')->get();
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
        $nodes = CategoryHierarchy::snapshot();
        $nodes[$category->id]['parent_id'] = $parentId;
        CategoryHierarchy::assertValid($nodes);
        CategoryHierarchy::assertPublishedPathsActive($nodes);
    }

    private function lockTree(): void
    {
        CatalogLock::tree();
    }
}
