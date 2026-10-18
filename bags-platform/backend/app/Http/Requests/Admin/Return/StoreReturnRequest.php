<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** إنشاء مرتجع يدوي من خدمة العملاء لطلب مُسلَّم (عميل مسجل أو زائر). */
class StoreReturnRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.item_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
