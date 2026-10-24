<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Brand;

use App\Http\Requests\Admin\Concerns\NormalizesCatalogInput;
use App\Rules\MaxImagePixels;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
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
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'alpha_dash:ascii', new UniqueCatalogIdentifier('brands', 'slug', $this->route('brand')->id)],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:4096', new MaxImagePixels],
            'remove_logo' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
