<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BarcodeGenerator;
use Illuminate\Support\Facades\DB;

class VariantService
{
    public function create(Product $product, array $data): ProductVariant
    {
        $data = array_filter($data, fn ($value) => $value !== null);

        return DB::transaction(function () use ($product, $data) {
            Product::whereKey($product->id)->lockForUpdate()->first(); // منع تكرار الترقيم
            $this->assertUniqueCombination($product, $data);

            if (empty($data['sku'])) {
                $seq = $product->variants()->withTrashed()->count() + 1;
                do {
                    $sku = $product->code.'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
                    $seq++;
                } while (ProductVariant::withTrashed()->where('sku', $sku)->exists());
                $data['sku'] = $sku;
            }

            $variant = $product->variants()->create($data);

            if (empty($variant->barcode)) {
                $variant->update(['barcode' => BarcodeGenerator::fromId($variant->id)]);
            }

            return $variant;
        });
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        foreach (['cost_price', 'reorder_level', 'sort_order', 'is_active'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                unset($data[$field]);
            }
        }

        return DB::transaction(function () use ($variant, $data) {
            $this->assertUniqueCombination($variant->product, array_merge($variant->only(['color_name_en', 'size', 'capacity']), $data), $variant->id);

            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertNotLastActiveOfPublished($variant);
            }
            $variant->update($data);

            return $variant->fresh('media');
        });
    }

    public function delete(ProductVariant $variant): void
    {
        $this->assertNotLastActiveOfPublished($variant);
        $variant->delete();
        // TODO مرحلة المخزون: منع الحذف لو عليه رصيد
    }

    private function assertUniqueCombination(Product $product, array $d, ?int $ignoreId = null): void
    {
        $exists = $product->variants()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => isset($d['color_name_en']) ? $q->where('color_name_en', $d['color_name_en']) : $q->whereNull('color_name_en'))
            ->where(fn ($q) => isset($d['size']) ? $q->where('size', $d['size']) : $q->whereNull('size'))
            ->where(fn ($q) => isset($d['capacity']) ? $q->where('capacity', $d['capacity']) : $q->whereNull('capacity'))
            ->exists();
        if ($exists) {
            throw new BusinessException('يوجد متغير بنفس اللون والمقاس والسعة لهذا المنتج');
        }
    }

    private function assertNotLastActiveOfPublished(ProductVariant $variant): void
    {
        $product = $variant->product;
        if ($product->status === ProductStatus::Published
            && $product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->doesntExist()) {
            throw new BusinessException('لا يمكن حذف/تعطيل آخر متغير نشط في منتج منشور');
        }
    }
}
