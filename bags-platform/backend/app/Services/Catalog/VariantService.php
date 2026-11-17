<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Exceptions\BusinessException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequestItem;
use App\Models\StockLevel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manages product variant creation, SKU and barcode generation, combination uniqueness, and deletion safety.
 */
class VariantService
{
    public function __construct(private ProductPublishabilityChecker $publishability) {}

    /**
     * Creates a new product variant, generating sequence SKU and barcode when omitted.
     *
     * @param  Product  $product  Parent product model.
     * @param  array<string, mixed>  $data  Variant attributes.
     * @param  int|null  $expectedProductVersion  Optimistic concurrency version of the parent product.
     * @return ProductVariant Created variant instance.
     *
     * @throws BusinessException If unique combination exists, SKU collides, or publish invariants fail.
     */
    public function create(Product $product, array $data, ?int $expectedProductVersion = null): ProductVariant
    {
        $data = $this->normalizeIdentifiers($data);

        // Retry once on generated SKU races before surfacing unique constraint collisions as 409 conflicts.
        $autoSku = empty($data['sku']);
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($product, $data, $expectedProductVersion) {
                    // Lock product row to serialize variant index numbering and prevent duplicate SKU sequences.
                    $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                    if ($expectedProductVersion !== null && (int) $product->lock_version !== $expectedProductVersion) {
                        throw new BusinessException('المنتج تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
                    }
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
                        $variant->updateQuietly(['barcode' => BarcodeGenerator::fromId($variant->id)]);
                    }

                    // Validate publishability conditions to ensure adding a variant does not break publishing invariants.
                    if ($product->status === ProductStatus::Published) {
                        $this->publishability->assertPublishable($product);
                    }

                    DB::afterCommit(function () use ($product): void {
                        app(RetailPriceSnapshot::class)->refresh($product->id);
                        StoreCatalogService::forgetStorefrontCaches();
                    });

                    return $variant;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($autoSku && $attempt === 0) {
                    continue;
                }
                throw new BusinessException('الـ SKU أو الباركود مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
            }
        }
    }

    /**
     * Updates an existing product variant, enforcing unique combinations and optimistic locks.
     *
     * @param  ProductVariant  $variant  Target variant to update.
     * @param  array<string, mixed>  $data  Updated variant attributes.
     * @return ProductVariant Refreshed variant instance.
     *
     * @throws BusinessException If version conflicts, duplicate combination exists, or SKU collides.
     */
    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $expectedVersion = $data['expected_version'] ?? null;
        unset($data['expected_version']);
        $data = $this->normalizeIdentifiers($data);
        foreach (['cost_price', 'reorder_level', 'sort_order', 'is_active'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                unset($data[$field]);
            }
        }

        // Catch concurrent unique SKU or barcode collisions during updates and convert to 409 conflict responses.
        try {
            return DB::transaction(function () use ($variant, $data, $expectedVersion) {
                $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
                $variant = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && (int) $expectedVersion !== (int) $variant->lock_version) {
                    throw new BusinessException('المتغير تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
                }
                $this->assertUniqueCombination($product, array_merge($variant->only(['color_name_en', 'size', 'capacity']), $data), $variant->id);

                if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                    $this->assertNotLastActiveOfPublished($variant);
                }
                if (array_key_exists('barcode', $data) && blank($data['barcode'])) {
                    $data['barcode'] = BarcodeGenerator::fromId($variant->id);
                }
                $variant->update($data);

                // Re-assert product publishability inside transaction to maintain catalog consistency.
                if ($product->status === ProductStatus::Published) {
                    $this->publishability->assertPublishable($product);
                }

                DB::afterCommit(function () use ($product): void {
                    app(RetailPriceSnapshot::class)->refresh($product->id);
                    StoreCatalogService::forgetStorefrontCaches();
                });

                return $variant->fresh(['media', 'product']);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BusinessException('الـ SKU أو الباركود مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
        }
    }

    /**
     * Soft-deletes a variant if no non-zero inventory balances exist.
     *
     * @param  ProductVariant  $variant  Variant instance to soft-delete.
     * @param  int|null  $expectedVersion  Optimistic concurrency version.
     *
     * @throws BusinessException If variant has non-zero stock or is the last active variant on a published product.
     */
    public function delete(ProductVariant $variant, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($variant, $expectedVersion): void {
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            // Lock variant row before inventory check so concurrent movements serialize and prevent phantom zero balances.
            ProductVariant::whereKey($variant->id)->orderBy('id')->lockForUpdate()->firstOrFail();
            $variant = $product->variants()->whereKey($variant->id)->firstOrFail();
            if ($expectedVersion !== null && (int) $variant->lock_version !== $expectedVersion) {
                throw new BusinessException('المتغير تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }
            $this->assertNotLastActiveOfPublished($variant);
            if (OrderItem::query()->where('product_variant_id', $variant->id)
                ->whereHas('order', fn ($query) => $query->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Processing->value,
                    OrderStatus::Shipped->value,
                ]))->exists()) {
                throw new BusinessException('لا يمكن حذف متغير مرتبط بطلب نشط؛ استخدم الأرشفة بدلًا من الحذف.');
            }
            if (ReturnRequestItem::query()->where('product_variant_id', $variant->id)
                ->whereHas('returnRequest', fn ($query) => $query->whereIn('status', [
                    ReturnStatus::Pending->value,
                    ReturnStatus::Approved->value,
                    ReturnStatus::Received->value,
                ]))->exists()) {
                throw new BusinessException('لا يمكن حذف متغير مرتبط بمرتجع مفتوح؛ أكمل معالجة المرتجع أولًا.');
            }
            // Prevent soft-deleting variants with non-zero stock to avoid stranding physical inventory.
            if (StockLevel::query()->where('product_variant_id', $variant->id)->hasNonZeroBalance()->exists()) {
                throw new BusinessException('لا يمكن حذف متغير له رصيد مخزون؛ صفِّ رصيده أولًا أو عطّله بدلًا من حذفه.');
            }
            $variant->delete();

            if ($product->status === ProductStatus::Published) {
                $this->publishability->assertPublishable($product);
            }

            DB::afterCommit(function () use ($product): void {
                app(RetailPriceSnapshot::class)->refresh($product->id);
                StoreCatalogService::forgetStorefrontCaches();
            });
        });
    }

    /**
     * Trims user-supplied identifiers so whitespace cannot bypass auto-generation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeIdentifiers(array $data): array
    {
        foreach (['sku', 'barcode'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $trimmed = trim($data[$field]);
                if ($trimmed === '') {
                    unset($data[$field]);
                } else {
                    $data[$field] = $trimmed;
                }
            }
        }

        return array_filter($data, fn ($value) => $value !== null);
    }

    private function assertUniqueCombination(Product $product, array $d, ?int $ignoreId = null): void
    {
        // Compare option attributes case-insensitively to prevent duplicate variant combinations.
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
