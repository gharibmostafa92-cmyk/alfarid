<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
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
            'from_branch_id' => ['required', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'to_branch_id' => ['required', 'integer', 'min:1', Rule::exists('branches', 'id'), 'different:from_branch_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', Rule::exists('product_variants', 'id')],
            'items.*.from_location_id' => [
                'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $this->input('from_branch_id')),
            ],
            'items.*.to_location_id' => [
                'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $this->input('to_branch_id')),
            ],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
        ];
    }
}
