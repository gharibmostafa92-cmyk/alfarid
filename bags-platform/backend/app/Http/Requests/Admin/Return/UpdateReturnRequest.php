<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تعديل إداري على مرتجع: ملاحظات/طريقة رد، أو إغلاقه برفض/إلغاء (بقية الانتقالات من نقاط الإجراءات). */
class UpdateReturnRequest extends FormRequest
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
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'status' => ['nullable', Rule::in(['rejected', 'cancelled'])],
        ];
    }
}
