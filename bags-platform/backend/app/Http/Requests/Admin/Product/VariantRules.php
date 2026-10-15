<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use Illuminate\Validation\Rule;

// قواعد الـ validation المشتركة لمتغيرات المنتج (variants.*) — تُدمج في StoreProductRequest.
class VariantRules
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function base(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')],
            'barcode' => ['nullable', 'string', 'size:13', Rule::unique('product_variants', 'barcode')],
            'color_name_ar' => ['nullable', 'string', 'max:150'],
            'color_name_en' => ['nullable', 'string', 'max:150'],
            'color_hex' => ['nullable', 'string', 'size:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
