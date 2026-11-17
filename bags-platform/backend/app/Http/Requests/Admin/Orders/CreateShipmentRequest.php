<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Orders;

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
        $idempotencyKey = $this->header('Idempotency-Key');
        $isReplay = is_string($idempotencyKey) && $idempotencyKey !== '' && \Illuminate\Support\Facades\DB::table('operation_idempotency_keys')
            ->where('operation', 'admin_order_shipment')
            ->where('key', $idempotencyKey)
            ->exists();

        return [
            'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
            // Optional: when omitted, an internal tracking number is automatically generated.
            'awb_number' => array_filter([
                'nullable',
                'string',
                'max:100',
                $isReplay ? null : 'unique:shipments,awb_number',
            ]),
        ];
    }
}
