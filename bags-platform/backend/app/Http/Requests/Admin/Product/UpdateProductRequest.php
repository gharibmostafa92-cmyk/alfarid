<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        return [
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'alpha_dash', 'max:255', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'material_ar' => ['sometimes', 'nullable', 'string', 'max:150'],
            'material_en' => ['sometimes', 'nullable', 'string', 'max:150'],
            'length_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'width_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'height_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999.999'],
            'warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'warranty_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_of_origin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(ProductStatus::class)],
            'sales_channel' => ['sometimes', 'required', Rule::enum(SalesChannel::class)],
            'is_featured' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
