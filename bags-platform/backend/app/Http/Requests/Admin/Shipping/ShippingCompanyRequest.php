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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = $this->route('shipping_company');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('shipping_companies', 'code')->ignore($company)],
            'api_credentials' => ['nullable', 'array'],
            // قالب رابط تتبع آمن (F-25): https فقط (يمنع javascript: ونحوه
            // كـ XSS مخزّن) ويجب أن يحوي العنصر النائب {awb}. لا نستخدم قاعدة
            // url لأنها ترفض الأقواس المعقوفة في القالب نفسه
            'tracking_url_template' => ['nullable', 'string', 'max:500', 'starts_with:https://', 'regex:/\{awb\}/'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
