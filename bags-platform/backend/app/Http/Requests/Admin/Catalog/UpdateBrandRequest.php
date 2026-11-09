<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Rules\MaxImagePixels;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateBrandRequest extends StoreBrandRequest
{
    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public static function baseRules(?int $ignoreId = null): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'alpha_dash:ascii', new UniqueCatalogIdentifier('brands', 'slug', $ignoreId)],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:4096', new MaxImagePixels],
            'remove_logo' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return self::baseRules($this->route('brand')?->id);
    }
}
