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
            'branch_id' => ['required', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'location_id' => [
                'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $this->input('branch_id')),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', Rule::exists('product_variants', 'id')],
            'items.*.adjustment_type' => ['required', Rule::in(['increase', 'decrease', 'mark_damaged'])],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'items.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
