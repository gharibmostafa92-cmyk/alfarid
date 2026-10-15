<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Variant;

use Illuminate\Validation\Rule;

// قواعد الـ validation المشتركة لمتغيرات المنتج — تُستخدم في إنشاء/تعديل المتغير وفي variants.* عند إنشاء منتج.
class VariantRules
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function base(?int $ignoreId = null): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('product_variants', 'sku')->ignore($ignoreId)],
            'barcode' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]+$/', Rule::unique('product_variants', 'barcode')->ignore($ignoreId)],
            'color_name_ar' => ['nullable', 'string', 'max:50'],
            'color_name_en' => ['nullable', 'string', 'max:50'],
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
