<?php

declare(strict_types=1);

namespace App\Http\Requests\Wholesale;

use App\Enums\WholesalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates wholesale offline payment proof submissions by customers.
 */
class StorePaymentNoticeRequest extends FormRequest
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
            'order_id' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::enum(WholesalePaymentMethod::class)],
            'bank_name' => ['required', 'string', 'max:255'],
            'reference_number' => ['required', 'string', 'max:255'],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
