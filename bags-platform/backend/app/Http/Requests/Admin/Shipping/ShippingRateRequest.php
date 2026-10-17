<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rate = $this->route('shipping_rate');

        return [
            'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
            'shipping_zone_id' => [
                'required', 'integer', 'exists:shipping_zones,id',
                Rule::unique('shipping_rates', 'shipping_zone_id')
                    ->where('shipping_company_id', $this->input('shipping_company_id'))
                    ->ignore($rate),
            ],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'cod_fee' => ['nullable', 'numeric', 'min:0'],
            'estimated_days_min' => ['required', 'integer', 'min:0'],
            'estimated_days_max' => ['required', 'integer', 'gte:estimated_days_min'],
        ];
    }
}
