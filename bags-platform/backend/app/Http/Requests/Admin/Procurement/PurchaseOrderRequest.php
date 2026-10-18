<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * إنشاء/تعديل أمر شراء: بنود صريحة، أو ربط بطلب داخلي معتمد تُنسخ بنوده
 * (عندها branch_id والبنود اختيارية وتُستكمل من الطلب).
 */
class PurchaseOrderRequest extends FormRequest
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
            'supplier_id' => [$isStore ? 'required' : 'sometimes', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['nullable', 'required_without:purchase_request_id', 'integer', 'exists:branches,id'],
            'purchase_request_id' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'required_without:purchase_request_id', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.ordered_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
