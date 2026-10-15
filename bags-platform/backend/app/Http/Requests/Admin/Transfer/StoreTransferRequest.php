<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
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
        return [
            'from_branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'to_branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true), 'different:from_branch_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1'],
            'items.*.from_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('from_branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items.*.to_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('to_branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items.*.requested_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
