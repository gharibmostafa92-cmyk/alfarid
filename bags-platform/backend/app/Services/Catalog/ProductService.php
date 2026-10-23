<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\SlugService;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    public function create(array $data, User $user): Product
    {
        return DB::transaction(function () use ($data, $user) {
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
    }

    public function update(Product $product, array $data, User $user): Product
    {
        return DB::transaction(function () use ($product, $data, $user) {
            CatalogLock::tree(shared: true);
            // قفل صف المنتج طول التعديل عشان فحص النشر ميعديش مع تعديل متزامن
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

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

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            // حذف المنتج يحذف متغيراته ناعمًا: أي رصيد على أي منها يُحبس (M-9)
            if (StockLevel::query()->whereIn('product_variant_id', $product->variants()->pluck('id'))
                ->where(fn ($q) => $q->where('on_hand', '<>', 0)->orWhere('reserved', '<>', 0)
                    ->orWhere('damaged', '<>', 0)->orWhere('in_transit', '<>', 0))
                ->exists()) {
                throw new BusinessException('لا يمكن حذف منتج له متغيرات برصيد مخزون؛ صفِّ الأرصدة أولًا.');
            }
            $product->variants()->delete();  // soft
            $product->delete();              // soft
        });
        // TODO مرحلة الطلبات: منع الحذف لو المنتج عليه طلبات، وبدل كده يتعمل archived
    }
}
