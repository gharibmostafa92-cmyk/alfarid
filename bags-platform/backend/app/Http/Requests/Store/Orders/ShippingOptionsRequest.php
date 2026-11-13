<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Orders;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingOptionsRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'recipient_phone' => ['sometimes', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            // Weight and subtotal are calculated server-side from cart items; payment_method determines applicable COD surcharge.
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
        ];
    }
}
