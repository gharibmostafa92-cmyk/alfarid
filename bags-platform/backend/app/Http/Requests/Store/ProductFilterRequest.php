<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

use App\Enums\Occasion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['prohibited'],
            'color' => ['nullable', 'string', 'max:100'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', ...($this->filled('price_min') ? ['gte:price_min'] : [])],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['sometimes', 'nullable', Rule::in(['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('storefront.max_per_page', 48)],
            'occasions' => ['sometimes', 'array', 'max:4'],
            'occasions.*' => ['string', 'distinct', Rule::enum(Occasion::class)],
            // Reject one-character searches before running catalog queries.
            'search' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
        if (is_string($this->input('brand')) || is_int($this->input('brand'))) {
            $this->merge(['brand' => [(string) $this->input('brand')]]);
        }
    }
}
