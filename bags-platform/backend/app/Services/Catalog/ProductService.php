<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\SlugService;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    public function create(array $data, User $user): Product
    {
        return DB::transaction(function () use ($data, $user) {
            $variants = $data['variants'] ?? [];
            unset($data['variants']);
            $data = array_filter($data, fn ($value) => $value !== null);

            $data['slug'] = $data['slug'] ?? SlugService::make(Product::class, $data['name_en']);
            $data['created_by'] = $user->id;
            $wantsPublish = ($data['status'] ?? 'draft') === ProductStatus::Published->value;
            $data['status'] = ProductStatus::Draft->value; // دايمًا يتعمل draft الأول

            // قفل صفوف القسم والبراند بيمنع حذفهم في نفس لحظة إنشاء المنتج عليهم
            Category::whereKey($data['category_id'])->lockForUpdate()->first();
            if (! empty($data['brand_id'])) {
                Brand::whereKey($data['brand_id'])->lockForUpdate()->first();
            }

            $product = Product::create($data);
            $product->update(['code' => 'P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($variants as $v) {
                app(VariantService::class)->create($product, $v);
            }

            if ($wantsPublish) {
                $this->changeStatus($product, ProductStatus::Published, $user);
            }

            return $product->fresh(['category', 'brand', 'variants.media', 'media']);
        });
    }

    public function update(Product $product, array $data, User $user): Product
    {
        return DB::transaction(function () use ($product, $data, $user) {
            // قفل صف المنتج طول التعديل عشان فحص النشر ميعديش مع تعديل متزامن
            $product = Product::whereKey($product->id)->lockForUpdate()->first() ?? $product;

            $newStatus = isset($data['status']) ? ProductStatus::from($data['status']) : null;
            unset($data['status']);

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

            return $product->fresh(['category', 'brand', 'variants.media', 'media']);
        });
    }

    public function changeStatus(Product $product, ProductStatus $status, User $user): void
    {
        // قفل صف المنتج وقسمه قبل الفحص والنشر، عشان مفيش تعديل متزامن يعدي بين الفحص والحفظ
        Product::whereKey($product->id)->lockForUpdate()->first();
        if ($product->category_id) {
            Category::whereKey($product->category_id)->lockForUpdate()->first();
        }
        $product->refresh();

        if ($status === ProductStatus::Published) {
            if (! $user->can('products.publish')) {
                throw new BusinessException('ليس لديك صلاحية نشر المنتجات', [], 403);
            }
            $this->assertPublishable($product);
            $product->published_at ??= now();
        }
        $product->status = $status;
        $product->save();
    }

    public function assertPublishable(Product $product): void
    {
        $this->publishability->assertPublishable($product);
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product->variants()->delete();  // soft
            $product->delete();              // soft
        });
        // TODO مرحلة الطلبات: منع الحذف لو المنتج عليه طلبات، وبدل كده يتعمل archived
    }
}
