<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransferRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fromBranchId = filter_var($this->input('from_branch_id', $this->route('transfer')?->from_branch_id), FILTER_VALIDATE_INT) ?: 0;
        $toBranchId = filter_var($this->input('to_branch_id', $this->route('transfer')?->to_branch_id), FILTER_VALIDATE_INT) ?: 0;

        return [
            'from_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'to_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_with:items', 'bail', 'integer', 'min:1'],
            'items.*.from_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $fromBranchId),
            ],
            'items.*.to_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $toBranchId),
            ],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
