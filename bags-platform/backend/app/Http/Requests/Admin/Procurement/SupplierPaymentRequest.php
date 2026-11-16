<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use App\Enums\Procurement\SupplierPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record supplier payment with balance ledger and invoice settlement in SupplierFinanceService.
 */
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
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'supplier_invoice_id' => ['nullable', 'integer', 'exists:supplier_invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::enum(SupplierPaymentMethod::class)],
            'reference_no' => ['required_unless:payment_method,cash', 'nullable', 'string', 'max:100'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
