<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'company_logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:100'],

            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'default_tax_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'prices_include_tax' => ['sometimes', 'boolean'],

            'invoice_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'invoice_next_number' => ['sometimes', 'integer', 'min:1'],
            'invoice_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'order_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'order_next_number' => ['sometimes', 'integer', 'min:1'],
            'order_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'purchase_order_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'purchase_order_next_number' => ['sometimes', 'integer', 'min:1'],
            'quotation_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'quotation_next_number' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $allowedKeys = array_keys($this->rules());
        $body = $this->isJson() ? $this->json()->all() : $this->request->all();
        $unknownKeys = array_diff(array_keys($body), $allowedKeys, ['_method', '_token']);

        $validator->after(function (Validator $validator) use ($unknownKeys): void {
            foreach ($unknownKeys as $unknownKey) {
                $validator->errors()->add($unknownKey, 'إعداد غير معروف.');
            }
        });
    }
}
