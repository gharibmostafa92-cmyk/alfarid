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
        $branchId = $this->input('branch_id', $this->route('stocktake')?->branch_id);

        return [
            'branch_id' => ['sometimes', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'location_id' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $branchId),
            ],
            'status' => ['sometimes', Rule::in([StocktakeStatus::InProgress->value, StocktakeStatus::Cancelled->value])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.product_variant_id' => [
                'required_with:items', 'integer', 'min:1', 'distinct', Rule::exists('product_variants', 'id'),
            ],
            'items.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
        ];
    }
}
