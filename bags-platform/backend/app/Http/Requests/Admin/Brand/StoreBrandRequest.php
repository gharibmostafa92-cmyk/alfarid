<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Brand;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrandRequest extends FormRequest
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
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('brands', 'slug')],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image:allow_svg', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
        ];
    }
}
