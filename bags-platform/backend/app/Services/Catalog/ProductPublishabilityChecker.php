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
    /** @return list<array{key:string, ok:bool, label:string, hint:?string}> */
    public function evaluate(Product $product): array
    {
        $active = $product->relationLoaded('variants')
            ? $product->variants->where('is_active', true)->values()
            : $product->variants()->where('is_active', true)->get();

        $activeOk = $active->isNotEmpty();
        $retailOk = ! $product->sales_channel->allowsRetail()
            || ($activeOk && ! $active->contains(fn ($v) => ! $v->retail_price || $v->retail_price <= 0));
        $wholesaleOk = ! $product->sales_channel->allowsWholesale()
            || ($activeOk && ! $active->contains(fn ($v) => ! $v->wholesale_price || $v->wholesale_price <= 0));
        $imageOk = $this->hasImages($product);
        $categoryOk = CategoryHierarchy::activePath($product->category);

        return [
            [
                'key' => 'active_variant',
                'ok' => $activeOk,
                'label' => 'يجب وجود متغير واحد نشط على الأقل',
                'hint' => $activeOk ? null : 'ضف متغير أو فعّل متغير موجود.',
            ],
            [
                'key' => 'retail_price',
                'ok' => $retailOk,
                'label' => 'كل المتغيرات النشطة لازم يكون لها سعر قطاعي',
                'hint' => $retailOk ? null : 'قولي سعر القطاعي لكل متغير أو سعر واحد للكل',
            ],
            [
                'key' => 'wholesale_price',
                'ok' => $wholesaleOk,
                'label' => 'كل المتغيرات النشطة لازم يكون لها سعر جملة',
                'hint' => $wholesaleOk ? null : 'حط سعر جملة لكل المتغيرات النشطة.',
            ],
            [
                'key' => 'image',
                'ok' => $imageOk,
                'label' => 'يجب رفع صورة واحدة على الأقل',
                'hint' => $imageOk ? null : 'ابعتلي صور المنتج هنا في الشات',
            ],
            [
                'key' => 'category_path',
                'ok' => $categoryOk,
                'label' => 'القسم أو أحد أقسامه الأب غير نشط',
                'hint' => $categoryOk ? null : ($product->category ? "القسم {$product->category->name_ar} متعطل؛ أفعّله؟" : 'فعّل القسم أو انقل المنتج لقسم نشط.'),
            ],
        ];
    }

    public function assertPublishable(Product $product): void
    {
        // نشتغل على نسخة حديثة من قاعدة البيانات عشان الفحص يشوف آخر تعديلات
        $product = $product->fresh() ?? $product;

        $errors = array_column(array_filter($this->evaluate($product), fn ($c) => ! $c['ok']), 'label');
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
