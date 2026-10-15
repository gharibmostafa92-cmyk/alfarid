<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use App\Services\SlugService;
use Illuminate\Support\Facades\DB;

class ProductService
{
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

            $product = Product::create($data);
            $product->update(['code' => 'P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($variants as $v) {
                app(VariantService::class)->create($product, $v);
            }

            if ($wantsPublish) {
                $this->changeStatus($product, ProductStatus::Published, $user);
            }

            return $product->fresh(['category', 'brand', 'variants', 'media']);
        });
    }

    public function update(Product $product, array $data, User $user): Product
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $newStatus = isset($data['status']) ? ProductStatus::from($data['status']) : null;
            unset($data['status']);

            if (array_key_exists('slug', $data) && empty($data['slug'])) {
                $data['slug'] = SlugService::make(Product::class, $data['name_en'] ?? $product->name_en, $product->id);
            }

            if (array_key_exists('is_featured', $data) && $data['is_featured'] === null) {
                unset($data['is_featured']);
            }

            $product->update($data);

            if ($newStatus && $newStatus !== $product->status) {
                $this->changeStatus($product, $newStatus, $user);
            }

            return $product->fresh(['category', 'brand', 'variants', 'media']);
        });
    }

    public function changeStatus(Product $product, ProductStatus $status, User $user): void
    {
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
        $errors = [];
        $active = $product->variants()->where('is_active', true)->get();

        if ($active->isEmpty()) {
            $errors[] = 'يجب وجود متغير واحد نشط على الأقل';
        }
        if ($product->sales_channel->allowsRetail() && $active->contains(fn ($v) => ! $v->retail_price || $v->retail_price <= 0)) {
            $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر قطاعي';
        }
        if ($product->sales_channel->allowsWholesale() && $active->contains(fn ($v) => ! $v->wholesale_price || $v->wholesale_price <= 0)) {
            $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر جملة';
        }
        if ($product->getMedia('images')->isEmpty()) {
            $errors[] = 'يجب رفع صورة واحدة على الأقل';
        }
        if (! $product->category?->is_active) {
            $errors[] = 'القسم غير نشط';
        }

        if ($errors) {
            throw new BusinessException('لا يمكن نشر المنتج', ['status' => $errors]);
        }
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
