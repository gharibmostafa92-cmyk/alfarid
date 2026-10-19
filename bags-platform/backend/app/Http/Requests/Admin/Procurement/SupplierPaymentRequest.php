<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use App\Enums\SupplierPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تسجيل دفعة لمورد — آثارها على الرصيد والفاتورة في SupplierFinanceService. */
class SupplierPaymentRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_invoice_id' => ['nullable', 'integer', 'exists:supplier_invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(array_column(SupplierPaymentMethod::cases(), 'value'))],
            'reference_no' => ['required_unless:payment_method,cash', 'nullable', 'string', 'max:100'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
