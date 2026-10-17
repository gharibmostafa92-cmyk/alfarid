<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class ShippingOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'total_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
