<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PriceQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.offered_unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
