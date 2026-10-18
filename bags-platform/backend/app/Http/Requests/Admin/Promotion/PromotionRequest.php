<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percentage_discount', 'fixed_discount', 'buy_x_get_y', 'free_shipping'])],
            // النسبة لا تتجاوز 100% (F-19) — يُفحص فقط للنوع النسبي
            'value' => ['nullable', 'numeric', 'min:0',
                Rule::when($this->input('type') === 'percentage_discount', ['max:100']),
            ],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['nullable', Rule::in(['all', 'categories', 'brands', 'variants'])],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
