<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** إتمام الاسترداد المالي لمرتجع مستلم: طريقة الرد (إن لم تُحدد عند الإنشاء) ومرجع العملية. */
class RefundReturnRequest extends FormRequest
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
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'refund_reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
