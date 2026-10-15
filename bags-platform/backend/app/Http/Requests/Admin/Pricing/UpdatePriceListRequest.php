<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Enums\PriceListType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * الربط بين النوع والعميل/المجموعة بيتحقق في PriceListService على الحالة النهائية
     * بعد الدمج (عشان تعديل الاسم بس على قائمة خاصة بعميل ميبقاش مطالب بإعادة إرسال العميل).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_column(PriceListType::cases(), 'value'))],
            'customer_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('customers', 'id')],
            'customer_group_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('customer_groups', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'currency' => ['sometimes', 'string', 'size:3'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'bail', 'integer', 'min:-10000', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
