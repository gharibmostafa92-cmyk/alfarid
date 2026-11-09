<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductPublishabilityChecker;

final class CatalogWarnings
{
    /**
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    public static function forVariantChange(ProductVariant $variant, array $data): array
    {
        $warnings = [];
        $product = $variant->product ?? Product::find($variant->product_id);
        if (! $product) {
            return $warnings;
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $otherActive = $product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->exists();
            if (! $otherActive) {
                if ($product->status === ProductStatus::Published) {
                    $warnings[] = 'ده آخر متغير نشط في منتج منشور — التعطيل هيترفض إلا لو أرشفت المنتج الأول.';
                } else {
                    $warnings[] = 'ده آخر متغير نشط في المنتج.';
                }
            }
        }

        $effectiveRetail = array_key_exists('retail_price', $data) ? $data['retail_price'] : $variant->retail_price;
        $effectiveWholesale = array_key_exists('wholesale_price', $data) ? $data['wholesale_price'] : $variant->wholesale_price;
        $effectiveActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $variant->is_active;

        if ($effectiveRetail !== null && $effectiveWholesale !== null && (float) $effectiveWholesale >= (float) $effectiveRetail) {
            $warnings[] = 'تنبيه: سعر الجملة أكبر من أو يساوي سعر القطاعي.';
        }

        if ($product->status === ProductStatus::Published && $effectiveActive) {
            if ($product->sales_channel->allowsRetail() && (! $effectiveRetail || (float) $effectiveRetail <= 0)) {
                $warnings[] = 'المنتج منشور وقناته تشمل القطاعي؛ إزالة سعر القطاعي هترفض التعديل.';
            }
            if ($product->sales_channel->allowsWholesale() && (! $effectiveWholesale || (float) $effectiveWholesale <= 0)) {
                $warnings[] = 'المنتج منشور وقناته تشمل الجملة؛ إزالة سعر الجملة هترفض التعديل.';
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    public static function forProductChange(Product $product, array $data, ProductPublishabilityChecker $checker): array
    {
        $warnings = [];
        if ($product->status !== ProductStatus::Published) {
            return $warnings;
        }

        $clone = $product->replicate();
        $clone->id = $product->id;
        $clone->fill($data);
        $clone->setRelation('variants', $product->variants);
        if (isset($data['category_id']) && (int) $data['category_id'] !== (int) $product->category_id) {
            $clone->setRelation('category', \App\Models\Category::find((int) $data['category_id']));
        } else {
            $clone->setRelation('category', $product->category);
        }

        foreach ($checker->evaluate($clone) as $check) {
            if (! $check['ok']) {
                $warnings[] = 'المنتج منشور والتعديل ده هيكسر شرط النشر: '.$check['label'];
            }
        }

        return $warnings;
    }
}
