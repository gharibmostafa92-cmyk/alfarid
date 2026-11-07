<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Receive purchase order goods: received quantities per line, optional bin location, and optional ASN match.
 */
class ReceivePurchaseOrderRequest extends FormRequest
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
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'supplier_delivery_id' => ['nullable', 'integer', 'exists:supplier_deliveries,id'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
        ];
    }
}
