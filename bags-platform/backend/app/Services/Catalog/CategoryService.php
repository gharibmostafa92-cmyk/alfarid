<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Services\Catalog\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages category hierarchy trees, slug assignments, media attachments, and cyclic reference guards.
 */
class CategoryService
{
    /**
     * Creates a new category within allowable tree depth.
     *
     * @param  array<string, mixed>  $data  Category attributes including name and optional parent_id.
     * @param  UploadedFile|null  $image  Optional category display image.
     * @return Category Created category instance.
     *
     * @throws BusinessException If maximum tree depth (3) is exceeded or slug collides.
     */
    public function create(array $data, ?UploadedFile $image): Category
    {
        // Acquire shared tree lock and retry once on generated slug races to allow concurrent leaf creations.
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

                // Perform media uploads in a separate transaction to avoid holding catalog tree locks during remote network I/O.
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

    /**
     * Updates an existing category, validating optimistic locks and hierarchy changes.
     *
     * @param  Category  $category  Target category model.
     * @param  array<string, mixed>  $data  Updated attributes.
     * @param  UploadedFile|null  $image  Replacement image file if provided.
     * @return Category Refreshed category instance.
     *
     * @throws BusinessException If version conflicts, hierarchy is invalid, or slug collides.
     */
    public function update(Category $category, array $data, ?UploadedFile $image): Category
    {
        $expectedVersion = $data['expected_version'] ?? null;
        unset($data['expected_version']);
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
        $apply = function (bool $exclusive) use ($category, $data, $expectedVersion): ?Category {
            return DB::transaction(function () use ($category, $data, $exclusive, $expectedVersion) {
                CatalogLock::tree(shared: ! $exclusive);
                $category = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && (int) $expectedVersion !== (int) $category->lock_version) {
                    throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
                }
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

                $category->update($data);

                return $category;
            });
        };

        // Catch concurrent unique slug collisions and convert to 409 conflict responses.
        try {
            $updated = $apply(false) ?? $apply(true);
        } catch (UniqueConstraintViolationException $e) {
            throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
        }

        // Execute media updates outside tree lock transaction to avoid prolonging catalog locks.
        if ($image) {
            $this->attachImage($updated, $image);
        } elseif ($removeImage) {
            DB::transaction(fn () => $updated->clearMediaCollection('image'));
        }

        return $updated;
    }

    /**
     * Attaches a category image in an isolated transaction without holding tree locks.
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

    /**
     * Deletes a category if it has no child categories or assigned products.
     *
     * @param  Category  $category  Category to delete.
     * @param  int|null  $expectedVersion  Optimistic concurrency version.
     * @return void
     *
     * @throws BusinessException If category has subcategories or assigned products.
     */
    public function delete(Category $category, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($category, $expectedVersion): void {
            $this->lockTree();
            $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && (int) $locked->lock_version !== $expectedVersion) {
                throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }

            if ($locked->children()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم يحتوي على أقسام فرعية');
            }

            if ($locked->products()->exists()) {
                throw new BusinessException('لا يمكن حذف قسم مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }

    /**
     * Reorders category items and moves nodes within the hierarchy under lock protection.
     *
     * @param  list<array{id: int, parent_id: int|null, sort_order: int, expected_version?: int|null}>  $items
     * @return void
     *
     * @throws BusinessException If cycle is detected or version mismatch occurs.
     */
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
                    if (isset($item['expected_version']) && (int) $item['expected_version'] !== (int) $category->lock_version) {
                        throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
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
                // Sort category updates by ID to enforce deterministic row-lock ordering and prevent deadlocks.
                $ordered = collect($items)->sortBy('id')->values()->all();
                $hasExpectedVersions = collect($ordered)->contains(fn ($item) => isset($item['expected_version']));
                $lockedCategories = $hasExpectedVersions
                    ? Category::whereIn('id', array_column($ordered, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                    : $categories;
                foreach ($ordered as $item) {
                    $locked = $lockedCategories->get($item['id'])
                        ?? throw new BusinessException('القسم لم يعد متاحًا؛ اطلب اقتراحًا جديدًا.', [], 409);
                    if (isset($item['expected_version']) && (int) $item['expected_version'] !== (int) $locked->lock_version) {
                        throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
                    }
                    $locked->update(['parent_id' => $nodes[$item['id']]['parent_id'], 'sort_order' => $item['sort_order']]);
                }

                return true;
            });
        };
        if (! $apply(false)) {
            $apply(true);
        }
    }

    /**
     * Builds the hierarchical category tree.
     *
     * @param  bool  $onlyActive  Whether to include only active categories.
     * @return Collection<int, Category>
     */
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

    /**
     * Asserts that assigning a parent category maintains valid published hierarchy and tree depth.
     *
     * @param  Category  $category  Target category.
     * @param  int|null  $parentId  Candidate parent identifier.
     * @return void
     *
     * @throws BusinessException If cycle or depth invariant is violated.
     */
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
