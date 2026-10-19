<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier\Delivery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * إشعار توريد (ASN) من بوابة المورد: كميات مشحونة فعليًا لكل بند
 * + فاتورة مبدئية مرفوعة (PDF/صورة) ورقمها الورقي اختياريًا.
 */
class StoreDeliveryRequest extends FormRequest
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
            'delivery_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'invoice_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.shipped_qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
        ];
    }
}
