<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
            // اختياري: بدونه يُولّد رقم بوليصة داخلي
            'awb_number' => ['nullable', 'string', 'max:100', 'unique:shipments,awb_number'],
        ];
    }
}
