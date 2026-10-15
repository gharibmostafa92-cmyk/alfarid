<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransferRequest extends FormRequest
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
        $fromBranchId = $this->input('from_branch_id', $this->route('transfer')?->from_branch_id);
        $toBranchId = $this->input('to_branch_id', $this->route('transfer')?->to_branch_id);

        return [
            'from_branch_id' => ['sometimes', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'to_branch_id' => ['sometimes', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_with:items', 'integer', 'min:1', Rule::exists('product_variants', 'id')],
            'items.*.from_location_id' => [
                'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $fromBranchId),
            ],
            'items.*.to_location_id' => [
                'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $toBranchId),
            ],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'gt:0', 'max:99999999.9999'],
        ];
    }
}
