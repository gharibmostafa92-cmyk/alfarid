<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user('staff') === null) {
            return false;
        }
        app(BranchAccess::class)->assertCentralAccess($this->user('staff'));

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $rules = [
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'company_logo' => ['sometimes', 'nullable', 'string', 'url:https', 'max:2048'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:100'],

            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'default_tax_rate' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'prices_include_tax' => ['sometimes', 'boolean'],

            'invoice_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'invoice_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'invoice_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'order_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'order_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'order_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'purchase_order_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'purchase_order_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'purchase_order_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'quotation_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'quotation_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'quotation_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'movement_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'movement_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'movement_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'transfer_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'transfer_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'transfer_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'stocktake_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'stocktake_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'stocktake_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'return_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'return_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'return_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'purchase_request_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'purchase_request_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'purchase_request_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'delivery_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'delivery_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'delivery_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'supplier_invoice_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'supplier_invoice_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'supplier_invoice_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'supplier_payment_prefix' => ['sometimes', 'nullable', 'string', 'max:30'],
            'supplier_payment_next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'supplier_payment_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
            // 0 = الإرجاع متوقف، -1 = بلا حد زمني، قيمة موجبة = أيام من التسليم
            'return_window_days' => ['sometimes', 'integer', 'min:-1', 'max:3650'],
            'allow_self_approve_purchases' => ['sometimes', 'boolean'],
        ];
        $expectedRules = ['expected' => ['required', 'array']];
        foreach ($rules as $key => $keyRules) {
            $type = in_array('boolean', $keyRules, true) ? 'boolean'
                : (in_array('integer', $keyRules, true) ? 'integer' : (in_array('numeric', $keyRules, true) ? 'numeric' : 'string'));
            $expectedRules['expected.'.$key] = ['sometimes', 'nullable', $type];
        }

        return $rules + $expectedRules;
    }

    public function withValidator(Validator $validator): void
    {
        $allowedKeys = array_values(array_filter(array_keys($this->rules()), fn ($key) => ! str_starts_with($key, 'expected.')));
        $body = $this->validationData();
        $unknownKeys = array_diff(array_keys($body), $allowedKeys, ['_method', '_token']);

        $validator->after(function (Validator $validator) use ($unknownKeys, $body): void {
            $changed = array_diff(array_keys($body), ['expected', '_method', '_token']);
            $expected = is_array($body['expected'] ?? null) ? $body['expected'] : [];
            foreach (array_diff($changed, array_keys($expected)) as $key) {
                $validator->errors()->add('expected.'.$key, 'القيمة الأصلية مطلوبة لهذا الإعداد.');
            }
            foreach (array_diff(array_keys($expected), $changed) as $key) {
                $validator->errors()->add('expected.'.$key, 'قيمة أصلية غير مرتبطة بإعداد معدل.');
            }
            foreach ($unknownKeys as $unknownKey) {
                $validator->errors()->add($unknownKey, 'إعداد غير معروف.');
            }
        });
    }
}
