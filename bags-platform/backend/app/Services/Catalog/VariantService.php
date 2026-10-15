<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BarcodeGenerator;

class VariantService
{
    public function create(Product $product, array $data): ProductVariant
    {
        $data = array_filter($data, fn ($value) => $value !== null);

        if (empty($data['sku'])) {
            $data['sku'] = ($product->code ?? 'P'.$product->id).'-'.($product->variants()->count() + 1);
        }

        $variant = $product->variants()->create($data);

        if (empty($variant->barcode)) {
            $variant->update(['barcode' => BarcodeGenerator::fromId($variant->id)]);
        }

        return $variant;
    }
}
