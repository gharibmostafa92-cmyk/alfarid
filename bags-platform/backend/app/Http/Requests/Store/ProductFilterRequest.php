<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

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
            'brand' => ['nullable', 'array', 'max:50'],
            'brand.*' => ['string', 'max:255'],
            'color' => ['nullable', 'string', 'max:100'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', ...($this->filled('price_min') ? ['gte:price_min'] : [])],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'best_selling', 'price_asc', 'price_desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('brand')) || is_int($this->input('brand'))) {
            $this->merge(['brand' => [(string) $this->input('brand')]]);
        }
    }
}
