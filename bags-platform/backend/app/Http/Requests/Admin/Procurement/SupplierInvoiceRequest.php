<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update supplier invoice draft. Grand total is computed server-side (subtotal + tax).
 */
class SupplierInvoiceRequest extends FormRequest
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
        $isStore = $this->isMethod('POST');

        return [
            'supplier_id' => [$isStore ? 'required' : 'prohibited', 'integer', 'exists:suppliers,id'],
            'supplier_invoice_no' => [$isStore ? 'required' : 'sometimes', 'string', 'max:100'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'issue_date' => [$isStore ? 'required' : 'sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'subtotal' => [$isStore ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'tax_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
