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
            'branch_id' => [$isStore ? 'required_without:purchase_request_id' : 'sometimes', 'integer', 'exists:branches,id'],
            'purchase_request_id' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'items' => [$isStore ? 'required_without:purchase_request_id' : 'sometimes', 'array', 'min:1', 'max:50'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.ordered_qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
