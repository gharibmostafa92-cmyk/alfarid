<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($coupon)],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_shipping'])],
            // كوبون نسبي فوق 100% يعني خصمًا كاملًا وأكثر — ممنوع (M18)
            'value' => ['nullable', 'numeric', 'min:0', Rule::when($this->input('type') === 'percentage', ['max:100'])],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * (M1) فحص القيمة «الفعّالة» بعد الدمج مع الكوبون الحالي: قاعدة max:100
     * أعلاه تعتمد على النوع المُرسل، فطلب تعديل يغيّر النوع إلى percentage
     * دون إرسال value كان يترك قيمة قديمة (>100) تمرّ دون فحص.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $coupon = $this->route('coupon');
            $type = $this->input('type');
            // قيمة غير رقمية (بما فيها null الصريح) تعني «أبقِ الحالية» في
            // مسار التعديل — فالفحص على القيمة الفعلية لا المُرسلة
            $submitted = $this->input('value');
            $effective = is_numeric($submitted) ? (float) $submitted : (float) ($coupon?->value ?? 0);

            if ($type === 'percentage' && $effective > 100) {
                $validator->errors()->add('value', 'نسبة الكوبون لا تتجاوز 100%.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
