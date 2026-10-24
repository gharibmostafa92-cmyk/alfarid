<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Product;

/**
 * قواعد قابلية النشر: بتتفحص وقت النشر، وبتتعاد بعد أي تعديل على منتج منشور
 * عشان المنتج المنشور يفضل دايمًا مستوفي الشروط (H1).
 */
class ProductPublishabilityChecker
{
    public function assertPublishable(Product $product): void
    {
        // نشتغل على نسخة حديثة من قاعدة البيانات عشان الفحص يشوف آخر تعديلات
        $product = $product->fresh() ?? $product;

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
        if (! $this->hasImages($product)) {
            $errors[] = 'يجب رفع صورة واحدة على الأقل';
        }
        if (! CategoryHierarchy::activePath($product->category)) {
            $errors[] = 'القسم أو أحد أقسامه الأب غير نشط';
        }

        if ($errors) {
            throw new BusinessException('لا يمكن نشر المنتج', ['status' => $errors]);
        }
    }

    public function hasImages(Product $product): bool
    {
        return $product->media()->where('collection_name', 'images')->exists()
            || $product->variants()->where('is_active', true)
                ->whereHas('media', fn ($query) => $query->where('collection_name', 'images'))->exists();
    }
}
