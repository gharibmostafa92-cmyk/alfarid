<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use App\Enums\SupplierPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تعديل دفعة: الحقول غير المالية فقط — المبلغ والربط يُصحَّحان بحذف وإعادة إنشاء. */
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
            'payment_method' => ['sometimes', Rule::in(array_column(SupplierPaymentMethod::cases(), 'value'))],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
