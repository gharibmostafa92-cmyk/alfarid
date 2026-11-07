<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\IdempotencyFingerprint;
use App\Services\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manages product lifecycle operations, publishing constraints, status transitions, and deletion safety.
 */
class ProductService
{
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    /**
     * Creates a new draft product and its initial variants.
     *
     * @param  array<string, mixed>  $data  Product attributes and nested variants array.
     * @param  User  $user  Authenticated staff user creating the product.
     * @return Product Created product instance with relations loaded.
     *
     * @throws BusinessException If input status is not draft, category is invalid, or slug collides.
     */
    public function create(array $data, User $user): Product
    {
        // Replay existing product on retried create_key to prevent duplicate entries and numbered slug suffixes.
        $createKey = is_string($data['create_key'] ?? null) && $data['create_key'] !== '' ? $data['create_key'] : null;
        unset($data['create_key']);
        $requestHash = IdempotencyFingerprint::hash($data);
        if ($createKey !== null) {
            $existing = Product::withTrashed()->where('created_by', $user->id)->where('create_key', $createKey)->first();
            if ($existing) {
                return $this->replayCreation($existing, $requestHash);
            }
        }

        // Retry once on generated slug races and surface explicit slug collisions as 409 conflicts.
        $autoSlug = empty($data['slug'] ?? null);
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $user, $createKey, $requestHash) {
                    CatalogLock::tree(shared: true);
                    $data['brand_id'] = null;
                    $data['supplier_id'] = null;
                    $variants = $data['variants'] ?? [];
                    unset($data['variants']);
                    $data = array_filter($data, fn ($value) => $value !== null);

                    if (($data['status'] ?? 'draft') !== ProductStatus::Draft->value) {
                        throw new BusinessException('يجب إنشاء المنتج كمسودة ثم رفع صوره ونشره', ['status' => ['الحالة المسموحة عند الإنشاء هي draft']]);
                    }

                    $data['slug'] = $data['slug'] ?? SlugService::make(Product::class, $data['name_en']);
                    $data['created_by'] = $user->id;
                    $data['create_key'] = $createKey;
                    $data['create_request_hash'] = $createKey !== null ? $requestHash : null;
                    // Enforce draft status on creation before media upload and publishing.
                    $data['status'] = ProductStatus::Draft->value;

                    // Shared-lock category to prevent concurrent deletion while assigning new product to it.
                    $this->lockReference(Category::class, (int) $data['category_id'], 'category_id');

                    $product = Product::create($data);
                    $product->update(['code' => 'P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);

                    foreach ($variants as $v) {
                        app(VariantService::class)->create($product, $v);
                    }

                    return $product->fresh(['category.media', 'variants.media', 'media']);
                });
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent retry with the same key may have won the race.
                if ($createKey !== null) {
                    $winner = Product::withTrashed()->where('created_by', $user->id)->where('create_key', $createKey)->first();
                    if ($winner) {
                        return $this->replayCreation($winner, $requestHash);
                    }
                }
                // Distinguish slug collisions from child variant SKU/barcode unique violations.
                $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
                $isSlugConflict = str_contains($driverMessage, 'products_slug_lower_unique')
                    || str_contains($driverMessage, '(slug)');
                if ($autoSlug && $attempt === 0 && $isSlugConflict) {
                    $data['slug'] = SlugService::make(Product::class, $data['name_en']);

                    continue;
                }
                if ($isSlugConflict) {
                    throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
                }

                throw $e;
            }
        }
    }

    /**
     * Updates an existing product, verifying optimistic concurrency version and publishability.
     *
     * @param  Product  $product  Target product model.
     * @param  array<string, mixed>  $data  Updated attributes.
     * @param  User  $user  Authenticated staff user initiating the update.
     * @return Product Refreshed product instance.
     *
     * @throws BusinessException If version conflicts, category is missing, or publish criteria fail.
     */
    public function update(Product $product, array $data, User $user): Product
    {
        $expectedVersion = $data['expected_version'] ?? null;
        unset($data['expected_version']);
        if (! empty($data['expected_updated_at']) && $expectedVersion === null) {
            throw new BusinessException('أعد تحميل المنتج لإرسال رقم الإصدار قبل الحفظ.', ['expected_version' => ['رقم الإصدار مطلوب.']]);
        }
        unset($data['expected_updated_at']);

        // Catch concurrent unique slug collisions during updates and convert to 409 conflict responses.
        try {
            return DB::transaction(function () use ($product, $data, $user, $expectedVersion) {
                CatalogLock::tree(shared: true);
                // Exclusive lock product row during update to ensure publishability invariants hold against concurrent writes.
                $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

                if ($expectedVersion !== null && (int) $expectedVersion !== (int) $product->lock_version) {
                    throw new BusinessException('المنتج تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
                }

                unset($data['brand_id'], $data['supplier_id']);
                $newStatus = isset($data['status']) ? ProductStatus::from($data['status']) : null;
                unset($data['status']);

                if (array_key_exists('slug', $data) && empty($data['slug'])) {
                    $data['slug'] = SlugService::make(Product::class, $data['name_en'] ?? $product->name_en, $product->id);
                }
                $this->lockReference(Category::class, (int) ($data['category_id'] ?? $product->category_id), 'category_id');

                if (array_key_exists('is_featured', $data) && $data['is_featured'] === null) {
                    unset($data['is_featured']);
                }

                $product->update($data);

                if ($newStatus && $newStatus !== $product->status) {
                    $this->changeStatus($product, $newStatus, $user);
                } elseif ($product->status === ProductStatus::Published) {
                    // Re-validate publishability invariants when updating an already published product.
                    $this->assertPublishable($product);
                }

                return $product->fresh(['category.media', 'variants.media', 'media']);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Verify violation relates specifically to the slug before returning a 409 conflict.
            $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (str_contains($driverMessage, 'products_slug_lower_unique') || str_contains($driverMessage, '(slug)')) {
                throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
            }

            throw $e;
        }
    }

    private function replayCreation(Product $product, string $requestHash): Product
    {
        if ($product->trashed()) {
            throw new BusinessException('المنتج الخاص بطلب الإنشاء محذوف بالفعل.', [], 409);
        }
        if ($product->create_request_hash !== null && ! hash_equals($product->create_request_hash, $requestHash)) {
            throw new BusinessException('تم إنشاء المنتج من الطلب السابق ببيانات مختلفة؛ أعد تحميله قبل التعديل.', ['product_id' => [$product->id]], 409);
        }

        return $product->fresh(['category.media', 'variants.media', 'media']);
    }

    /**
     * Changes publication status of a product after evaluating permissions and catalog requirements.
     *
     * @param  Product  $product  Target product model.
     * @param  ProductStatus  $status  New publication status.
     * @param  User  $user  Authenticated staff user.
     * @param  int|null  $expectedVersion  Optimistic concurrency version.
     * @return void
     *
     * @throws BusinessException If permissions are insufficient or publication rules are unmet.
     */
    public function changeStatus(Product $product, ProductStatus $status, User $user, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($product, $status, $user, $expectedVersion): void {
            CatalogLock::tree(shared: true);
            // Lock product and parent category to prevent concurrent changes between validation and publish commit.
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && (int) $product->lock_version !== $expectedVersion) {
                throw new BusinessException('المنتج تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }
            if ($product->category_id) {
                $this->lockReference(Category::class, (int) $product->category_id, 'category_id');
            }
            $product->refresh();

            if ($product->status !== $status && ($product->status === ProductStatus::Published || $status === ProductStatus::Published) && ! $user->can('products.publish')) {
                throw new BusinessException('ليس لديك صلاحية تغيير نشر المنتجات', [], 403);
            }

            if ($status === ProductStatus::Published) {
                $this->assertPublishable($product);
                $product->published_at ??= now();
            }
            $product->status = $status;
            $product->save();
        });
        $product->refresh();
    }

    /**
     * Asserts that a product satisfies all business conditions required for publication.
     *
     * @param  Product  $product  Product to evaluate.
     * @return void
     *
     * @throws BusinessException If photos, variants, prices, or category status are deficient.
     */
    public function assertPublishable(Product $product): void
    {
        $this->publishability->assertPublishable($product);
    }

    private function lockReference(string $model, int $id, string $field): void
    {
        if (! $model::whereKey($id)->sharedLock()->first()) {
            throw new BusinessException('أحد المراجع المطلوبة لم يعد متاحًا', [$field => ['القيمة المحددة غير موجودة أو محذوفة']]);
        }
    }

    /**
     * Soft-deletes a product and its associated variants if no positive inventory balances exist.
     *
     * @param  Product  $product  Target product to soft-delete.
     * @param  User|null  $user  Authenticated user initiating deletion.
     * @param  int|null  $expectedVersion  Optimistic concurrency version.
     * @return void
     *
     * @throws BusinessException If non-zero stock balances remain or publishing permission is required.
     */
    public function delete(Product $product, ?User $user = null, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($product, $user, $expectedVersion): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($user && $product->status === ProductStatus::Published && ! $user->can('products.publish')) {
                throw new BusinessException('حذف منتج منشور يتطلب صلاحية النشر.', [], 403);
            }
            if ($expectedVersion !== null && (int) $product->lock_version !== $expectedVersion) {
                throw new BusinessException('المنتج تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }
            // Lock all variant rows in deterministic ID order before checking inventory balances to prevent concurrent stock receipts.
            $lockedIds = ProductVariant::where('product_id', $product->id)->orderBy('id')->lockForUpdate()->pluck('id');
            // Prevent soft-deleting products with non-zero stock to avoid stranding physical inventory.
            if (StockLevel::query()->whereIn('product_variant_id', $lockedIds)->hasNonZeroBalance()->exists()) {
                throw new BusinessException('لا يمكن حذف منتج له متغيرات برصيد مخزون؛ صفِّ الأرصدة أولًا.');
            }
            // Soft-delete variants one model at a time so the deleted event
            // fires (ProductVariant::booted removes dangling cart items);
            // a mass query delete would silently skip it.
            foreach ($product->variants()->orderBy('id')->get() as $variant) {
                $variant->delete();
            }
            $product->delete();
        });
        // TODO: Prevent deletion if product has existing orders; require archiving instead.
    }

    /**
     * Permanently deletes product and all associated variants and R2 storage media.
     *
     * @param  Product  $product  Product instance to permanently purge.
     * @return void
     *
     * @throws BusinessException If non-zero stock balances remain on any variant.
     */
    public function forceDelete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variants = $product->variants()->withTrashed()->reorder('id')->lockForUpdate()->get();
            if (StockLevel::query()->whereIn('product_variant_id', $variants->pluck('id'))->hasNonZeroBalance()->exists()) {
                throw new BusinessException('لا يمكن الحذف النهائي لمنتج له متغيرات برصيد مخزون؛ صفِّ الأرصدة أولًا.');
            }
            foreach ($variants as $variant) {
                $variant->clearMediaCollection('images');
                $variant->forceDelete();
            }
            $product->clearMediaCollection('images');
            $product->clearMediaCollection('video');
            $product->forceDelete();
        });
    }
}
