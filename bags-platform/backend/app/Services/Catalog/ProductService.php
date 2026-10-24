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

class ProductService
{
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    public function create(array $data, User $user): Product
    {
        // F9: the backoffice keeps the form alive across a lost response, so a
        // retried save with the same create_key replays the existing row
        // instead of creating a slug ...-2 duplicate.
        $createKey = is_string($data['create_key'] ?? null) && $data['create_key'] !== '' ? $data['create_key'] : null;
        unset($data['create_key']);
        $requestHash = IdempotencyFingerprint::hash($data);
        if ($createKey !== null) {
            $existing = Product::withTrashed()->where('created_by', $user->id)->where('create_key', $createKey)->first();
            if ($existing) {
                return $this->replayCreation($existing, $requestHash);
            }
        }

        // M1: concurrent creates can generate the same auto slug; retry once with
        // a fresh slug, and surface explicit-slug conflicts as 409 not 500.
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
                    $data['status'] = ProductStatus::Draft->value; // دايمًا يتعمل draft الأول

                    // قفل القسم يمنع حذفه أثناء إنشاء المنتج
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
                if ($autoSlug && $attempt === 0) {
                    $data['slug'] = SlugService::make(Product::class, $data['name_en']);

                    continue;
                }
                throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
            }
        }
    }

    public function update(Product $product, array $data, User $user): Product
    {
        $expectedVersion = $data['expected_version'] ?? null;
        unset($data['expected_version']);
        if (! empty($data['expected_updated_at']) && $expectedVersion === null) {
            throw new BusinessException('أعد تحميل المنتج لإرسال رقم الإصدار قبل الحفظ.', ['expected_version' => ['رقم الإصدار مطلوب.']]);
        }
        unset($data['expected_updated_at']);

        // F8: a concurrent update can win the slug between validation and the
        // write — surface it as 409, not 500.
        try {
            return DB::transaction(function () use ($product, $data, $user, $expectedVersion) {
                CatalogLock::tree(shared: true);
                // قفل صف المنتج طول التعديل عشان فحص النشر ميعديش مع تعديل متزامن
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
                    // تعديل من غير تغيير حالة على منتج منشور: القواعد بتتفحص من جديد
                    $this->assertPublishable($product);
                }

                return $product->fresh(['category.media', 'variants.media', 'media']);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
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

    public function changeStatus(Product $product, ProductStatus $status, User $user): void
    {
        DB::transaction(function () use ($product, $status, $user): void {
            CatalogLock::tree(shared: true);
            // قفل صف المنتج وقسمه قبل الفحص والنشر، عشان مفيش تعديل متزامن يعدي بين الفحص والحفظ
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
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

    public function delete(Product $product, ?User $user = null): void
    {
        DB::transaction(function () use ($product, $user): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($user && $product->status === ProductStatus::Published && ! $user->can('products.publish')) {
                throw new BusinessException('حذف منتج منشور يتطلب صلاحية النشر.', [], 403);
            }
            // H3: lock every variant row (id-ordered) before the balance check so
            // concurrent receipts serialize here instead of slipping underneath it.
            $lockedIds = ProductVariant::where('product_id', $product->id)->orderBy('id')->lockForUpdate()->pluck('id');
            // حذف المنتج يحذف متغيراته ناعمًا: أي رصيد على أي منها يُحبس (M-9)
            if (StockLevel::query()->whereIn('product_variant_id', $lockedIds)->hasNonZeroBalance()->exists()) {
                throw new BusinessException('لا يمكن حذف منتج له متغيرات برصيد مخزون؛ صفِّ الأرصدة أولًا.');
            }
            $product->variants()->delete();  // soft
            $product->delete();              // soft
        });
        // TODO مرحلة الطلبات: منع الحذف لو المنتج عليه طلبات، وبدل كده يتعمل archived
    }

    /**
     * F14: permanent removal also removes the media rows AND their R2 files —
     * soft-delete intentionally keeps them (old links/caches keep working),
     * but a force-delete must not leave publicly reachable files behind.
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
