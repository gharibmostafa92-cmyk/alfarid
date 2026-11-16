<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * On update, a due-date-only payload must still be compared with the stored issue date.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') || $this->filled('issue_date')) {
            return;
        }
        $invoice = $this->route('supplier_invoice');
        if ($invoice !== null && $invoice->issue_date !== null) {
            $this->merge(['issue_date' => $invoice->issue_date->toDateString()]);
        }
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
            'issue_date' => [$isStore ? 'required' : 'sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('issue_date'), ['after_or_equal:issue_date'])],
            'subtotal' => [$isStore ? 'required' : 'sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'tax_amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
