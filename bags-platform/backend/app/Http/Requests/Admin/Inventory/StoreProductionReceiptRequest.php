<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.adjust') && $this->user()->can('products.view_cost');
    }

    protected function prepareForValidation(): void
    {
        // L3: batch numbers are compared/unique-matched — trim whitespace.
        if (is_string($batch = $this->input('batch_number'))) {
            $this->merge(['batch_number' => mb_strtoupper(trim($batch))]);
        }
    }

    public function rules(): array
    {
        return [
            'batch_number' => ['required', 'string', 'max:100'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('branch_id', (int) $this->input('branch_id'))->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_variant_id,quantity,unit_cost'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ];
    }
}
