<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdjustmentRequest extends FormRequest
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
            'branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('is_active', true)->where('branch_id', filter_var($this->input('branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('product_variants', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'items.*.adjustment_type' => ['required', Rule::in(['increase', 'decrease', 'mark_damaged', 'write_off_damaged', 'restore_damaged'])],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'items.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
