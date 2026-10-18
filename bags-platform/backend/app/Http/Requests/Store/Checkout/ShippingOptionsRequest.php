<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // لا subtotal ولا وزن من العميل (F-29): الإجمالي يُحسب من السلة
            // على الخادم، وطريقة الدفع تحدد رسوم COD المعروضة
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
        ];
    }
}
