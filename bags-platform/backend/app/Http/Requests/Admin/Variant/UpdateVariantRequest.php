<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Variant;

use App\Http\Requests\Admin\Concerns\NormalizesCatalogInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVariantRequest extends FormRequest
{
    use NormalizesCatalogInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->normalizeCatalogIdentifiers();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $rules = VariantRules::base($this->route('variant')->id);

        foreach ($rules as $field => $rule) {
            if ($field === 'color_name_en') {
                $rule = array_values(array_filter($rule, fn ($value) => ! is_string($value) || ! str_starts_with($value, 'required_with:')));
            }
            if ($field === 'sku') {
                $rule = array_values(array_filter($rule, fn ($value) => $value !== 'nullable'));
                array_unshift($rule, 'required');
            }
            array_unshift($rule, 'sometimes');
            $rules[$field] = $rule;
        }

        // M5: رقم الإصدار إلزامي — الكتابة فوق أسعار (retail/wholesale) برقم
        // قديم دون 409 كانت تضيّع تعديلات أسعار حقيقية.
        $rules['expected_version'] = ['required', 'integer', 'min:0'];

        return $rules;
    }

    /**
     * M5: فترة انتقالية — العملاء القدامى الذين لا يرسلون رقم الإصدار
     * يُرفضون بـ 422، مع تسجيل تحذير لقياس حجمهم قبل الإلزام الكامل.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        if ($validator->errors()->has('expected_version')) {
            \Illuminate\Support\Facades\Log::warning('variant.update_without_expected_version', [
                'user_id' => $this->user()?->id,
                'variant_id' => $this->route('variant')?->id,
                'has_version' => $this->filled('expected_version'),
            ]);
        }

        parent::failedValidation($validator);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $color = array_replace($this->route('variant')->only(['color_name_en', 'color_name_ar', 'color_hex']), $this->only(['color_name_en', 'color_name_ar', 'color_hex']));
            if ((filled($color['color_name_ar']) || filled($color['color_hex'])) && blank($color['color_name_en'])) {
                $validator->errors()->add('color_name_en', 'اسم اللون الإنجليزي مطلوب عند وجود بيانات لون');
            }
        }];
    }
}
