<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates promotional campaign creation and update rules across catalog scopes.
 */
class PromotionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['applies_to' => $this->input('applies_to') ?? $this->route('promotion')?->applies_to ?? 'all']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetTable = match ($this->input('applies_to')) {
            'categories' => 'categories',
            'brands' => 'brands',
            'variants' => 'product_variants',
            default => null,
        };

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percentage_discount', 'fixed_discount', 'free_shipping'])],
            // Percentage discount rate is capped at 100%.
            'value' => ['required_unless:type,free_shipping', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2',
                Rule::when($this->input('type') === 'percentage_discount', ['max:100']),
            ],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'applies_to' => ['nullable', Rule::in(['all', 'categories', 'brands', 'variants'])],
            'target_ids' => ['nullable', 'array', 'max:500', 'required_if:applies_to,categories,brands,variants',
                ...($targetTable !== null ? [Rule::exists($targetTable, 'id')] : []),
            ],
            'target_ids.*' => ['integer', 'min:1', 'distinct'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
