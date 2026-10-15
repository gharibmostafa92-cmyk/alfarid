<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStocktakeRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.product_variant_id' => [
                'required_with:items', 'integer', 'min:1', 'distinct', Rule::exists('product_variants', 'id'),
            ],
            'items.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
        ];
    }
}
