<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        if (array_key_exists('is_active', $input) && $input['is_active'] === null) {
            $input['is_active'] = true;
        }
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtolower(trim($input['code']));
        }
        $this->replace($input);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = $this->route('shipping_company');

        return [
            'name' => ['required', 'string', 'max:255'],
            // Same alphabet and length as the public webhook path, so a saved code can receive events.
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/', Rule::unique('shipping_companies', 'code')->ignore($company)],
            'api_credentials' => ['nullable', 'array'],
            // قالب رابط تتبع آمن (F-25): https فقط (يمنع javascript: ونحوه
            // كـ XSS مخزّن) ويجب أن يحوي العنصر النائب {awb}. لا نستخدم قاعدة
            // url لأنها ترفض الأقواس المعقوفة في القالب نفسه
            'tracking_url_template' => ['nullable', 'string', 'max:500', 'starts_with:https://', 'regex:/\{awb\}/'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
