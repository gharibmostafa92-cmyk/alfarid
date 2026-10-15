<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use App\Enums\StocktakeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStocktakeRequest extends FormRequest
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
        $branchId = filter_var($this->input('branch_id', $this->route('stocktake')?->branch_id), FILTER_VALIDATE_INT) ?: 0;

        return [
            'branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => [
                'sometimes', 'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('is_active', true)->where('branch_id', $branchId),
            ],
            'status' => ['sometimes', Rule::in([StocktakeStatus::InProgress->value, StocktakeStatus::Cancelled->value])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.product_variant_id' => [
                'required_with:items', 'bail', 'integer', 'min:1', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ];
    }
}
