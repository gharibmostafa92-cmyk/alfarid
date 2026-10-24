<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Concerns\NormalizesCatalogInput;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    use NormalizesCatalogInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->normalizeCatalogIdentifiers();
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
        return [
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'alpha_dash:ascii', 'max:255', new UniqueCatalogIdentifier('products', 'slug', $this->route('product')->id)],
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
            // Timestamp-only locking cannot detect edits in the same second.
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'expected_version' => ['required_with:expected_updated_at', 'integer', 'min:0'],
        ];
    }
}
