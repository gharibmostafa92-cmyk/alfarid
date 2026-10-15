<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListItemRequest extends FormRequest
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
            'product_variant_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('product_variants', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'min_qty' => ['sometimes', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'price' => ['sometimes', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
