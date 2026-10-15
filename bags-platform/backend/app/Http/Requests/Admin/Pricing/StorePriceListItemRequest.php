<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListItemRequest extends FormRequest
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
            'product_variant_id' => ['required', 'integer', 'min:1', Rule::exists('product_variants', 'id')],
            'min_qty' => ['nullable', 'numeric', 'gt:0', 'max:99999999.9999'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
