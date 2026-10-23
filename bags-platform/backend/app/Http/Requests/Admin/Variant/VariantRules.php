<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Variant;

use App\Models\ProductVariant;
use App\Rules\UniqueCatalogIdentifier;
use Closure;
use Illuminate\Validation\Rule;

// قواعد الـ validation المشتركة لمتغيرات المنتج — تُستخدم في إنشاء/تعديل المتغير وفي variants.* عند إنشاء منتج.
class VariantRules
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function base(?int $ignoreId = null, string $prefix = ''): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:64', 'alpha_dash:ascii', new UniqueCatalogIdentifier('product_variants', 'sku', $ignoreId)],
            'barcode' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]+$/', Rule::unique('product_variants', 'barcode')->ignore($ignoreId),
                // صيغة 2 + 12 رقم محجوزة للباركود الداخلي اللي النظام بيولّده من id المتغير (M3)
                function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
                    if (is_string($value) && preg_match('/^2\d{12}$/', $value) === 1) {
                        $current = $ignoreId !== null
                            ? ProductVariant::whereKey($ignoreId)->value('barcode')
                            : null;
                        if ($value !== $current) {
                            $fail('صيغة الباركود دي محجوزة لباركودات النظام الداخلية');
                        }
                    }
                },
            ],
            'color_name_ar' => ['nullable', 'string', 'max:50'],
            // اسم اللون الإنجليزي هو مفتاح منع التكرار، فبيبقى مطلوب لو أي بيانات لون تانية موجودة
            'color_name_en' => ['nullable', 'string', 'max:50', 'required_with:'.$prefix.'color_name_ar,'.$prefix.'color_hex'],
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999'],
            'retail_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'compare_at_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'wholesale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
