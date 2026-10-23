<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Concerns\LimitsArrayInput;
use App\Http\Requests\Admin\Variant\VariantRules;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use LimitsArrayInput;

    protected function arrayLimits(): array
    {
        return ['variants' => 100];
    }

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
            'brand_id' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', new UniqueCatalogIdentifier('products', 'slug')],
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
            'status' => ['nullable', Rule::in(['draft'])],
            'sales_channel' => ['required', Rule::enum(SalesChannel::class)],
            'is_featured' => ['nullable', 'boolean'],
            // اختياري: متغيرات مع الإنشاء
            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*' => ['array:'.implode(',', array_keys(VariantRules::base()))],
        ];

        foreach (VariantRules::base(null, 'variants.*.') as $field => $rule) {
            if (in_array($field, ['sku', 'barcode'], true)) {
                $rule[] = 'distinct';
            }
            $rules["variants.*.$field"] = $rule;
        }

        return $rules;
    }
}
