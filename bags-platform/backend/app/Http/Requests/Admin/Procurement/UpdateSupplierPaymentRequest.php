<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use App\Enums\Procurement\SupplierPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update non-financial payment attributes; amount adjustments require voiding and recreating the payment.
 */
class UpdateSupplierPaymentRequest extends FormRequest
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
            'payment_method' => ['sometimes', Rule::enum(SupplierPaymentMethod::class)],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
