<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates promotional coupon creation and update requests.
 */
class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            // Case-insensitive uppercase uniqueness check prevents duplicate variants of the same code.
            'code' => ['required', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($coupon),
                function (string $attribute, mixed $value, \Closure $fail) use ($coupon): void {
                    if (is_string($value) && Coupon::query()
                        ->whereRaw('upper(code) = ?', [strtoupper($value)])
                        ->when($coupon, fn ($query) => $query->whereKeyNot($coupon->getKey()))
                        ->exists()) {
                        $fail('رمز الكوبون مستخدم بالفعل.');
                    }
                },
            ],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_shipping'])],
            // Percentage discount cannot exceed 100% of order merchandise value.
            'value' => ['required_unless:type,free_shipping', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2', Rule::when($this->input('type') === 'percentage', ['max:100'])],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validate effective merged value when switching coupon type to percentage without providing a new value.
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
