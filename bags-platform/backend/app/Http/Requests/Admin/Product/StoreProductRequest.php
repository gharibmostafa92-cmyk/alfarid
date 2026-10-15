<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Variant\VariantRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $rules = [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'supplier_id' => ['nullable', 'integer'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash', 'max:255', Rule::unique('products', 'slug')],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'material_ar' => ['nullable', 'string', 'max:150'],
            'material_en' => ['nullable', 'string', 'max:150'],
            'length_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'width_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'warranty_details' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'sales_channel' => ['required', Rule::enum(SalesChannel::class)],
            'is_featured' => ['nullable', 'boolean'],
            // اختياري: متغيرات مع الإنشاء
            'variants' => ['nullable', 'array'],
            'variants.*' => ['array'],
        ];

        foreach (VariantRules::base() as $field => $rule) {
            $rules["variants.*.$field"] = $rule;
        }

        return $rules;
    }
}
