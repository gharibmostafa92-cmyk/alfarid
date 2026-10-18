<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use Illuminate\Foundation\Http\FormRequest;

/** الموافقة المبدئية على مرتجع: فرع الاستلام + كميات معتمدة اختيارية لكل بند (الافتراضي: كامل المطلوب). */
class ApproveReturnRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.approved_qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
