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
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    public function create(Product $product, array $data): ProductVariant
    {
        $data = array_filter($data, fn ($value) => $value !== null);

        return DB::transaction(function () use ($product, $data) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail(); // منع تكرار الترقيم
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

            // متغير جديد على منتج منشور لازم ميكسرش قواعد النشر، وإلا التعديل كله يتلغي
            if ($product->status === ProductStatus::Published) {
                $this->publishability->assertPublishable($product);
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
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            $variant = $product->variants()->whereKey($variant->id)->firstOrFail();
            $this->assertUniqueCombination($product, array_merge($variant->only(['color_name_en', 'size', 'capacity']), $data), $variant->id);

            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertNotLastActiveOfPublished($variant);
            }
            if (array_key_exists('barcode', $data) && blank($data['barcode'])) {
                $data['barcode'] = BarcodeGenerator::fromId($variant->id);
            }
            $variant->update($data);

            // أي تعديل على متغير منتج منشور بيعيد فحص قواعد النشر جوه نفس الـ transaction
            if ($product->status === ProductStatus::Published) {
                $this->publishability->assertPublishable($product);
            }

            return $variant->fresh(['media', 'product']);
        });
    }

    public function delete(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            $variant = $product->variants()->whereKey($variant->id)->firstOrFail();
            $this->assertNotLastActiveOfPublished($variant);
            $variant->delete();

            if ($product->status === ProductStatus::Published) {
                $this->publishability->assertPublishable($product);
            }
        });
        // TODO مرحلة المخزون: منع الحذف لو عليه رصيد
    }

    private function assertUniqueCombination(Product $product, array $d, ?int $ignoreId = null): void
    {
        // المقارنة من غير حساسية لحالة الحروف: Black وblack نفس اللون
        $matchField = fn ($q, string $column, ?string $value) => $value === null
            ? $q->whereNull($column)
            : $q->whereRaw("lower({$column}) = ?", [mb_strtolower($value)]);

        $exists = $product->variants()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => $matchField($q, 'color_name_en', $d['color_name_en'] ?? null))
            ->where(fn ($q) => $matchField($q, 'size', $d['size'] ?? null))
            ->where(fn ($q) => $matchField($q, 'capacity', $d['capacity'] ?? null))
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
