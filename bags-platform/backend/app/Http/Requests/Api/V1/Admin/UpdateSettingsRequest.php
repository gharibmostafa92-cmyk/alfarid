<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(BranchAccess $branchAccess): bool
    {
        if ($this->user('staff') === null) {
            return false;
        }
        $branchAccess->assertCentralAccess($this->user('staff'));
        foreach (array_keys($this->validationData()) as $key) {
            if (str_starts_with($key, 'ai_')) {
                abort_unless($this->user('staff')->can('ai_agent.settings'), 403);
            }
        }

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
            // 0 = الإرجاع متوقف، -1 = بلا حد زمني، قيمة موجبة = أيام من التسليم
            'return_window_days' => ['sometimes', 'integer', 'min:-1', 'max:3650'],
            'allow_self_approve_purchases' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_daily_token_budget_per_user' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'ai_disabled_tools' => ['sometimes', 'array'],
            // L12: الأداة مقفولة مش تنسيق حر — الاسم لازم يكون في الـ registry
            // فعلًا، وإلا الغلطة الإملائية تعدي إعدادًا ميؤثر بلا سؤال.
            'ai_disabled_tools.*' => ['string', 'max:100', Rule::in(array_keys(app(\App\Services\Ai\ToolRegistry::class)->definitions()))],
            'ai_action_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'ai_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'ai_bulk_max_items' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];

        $sequenceTypes = [
            'invoice',
            'order',
            'purchase_order',
            'quotation',
            'movement',
            'transfer',
            'stocktake',
            'return',
            'purchase_request',
            'delivery',
            'supplier_invoice',
            'supplier_payment',
            // H1: أنواع كان الوكيل يقدر يعدّلها من update_document_numbering
            // والـ REST لأ — توحيد مصدر القواعد بين المسارين.
            'shift',
            'expense',
            'treasury_transfer',
            'payment_notice',
        ];
        foreach ($sequenceTypes as $type) {
            $rules["{$type}_prefix"] = ['sometimes', 'nullable', 'string', 'max:30'];
            $rules["{$type}_next_number"] = ['sometimes', 'integer', 'min:1', 'max:999999999'];
            $rules["{$type}_padding"] = ['sometimes', 'integer', 'min:1', 'max:20'];
        }

        $expectedRules = ['expected' => ['required', 'array']];
        foreach ($rules as $key => $keyRules) {
            $type = in_array('boolean', $keyRules, true) ? 'boolean'
                : (in_array('integer', $keyRules, true) ? 'integer' : (in_array('numeric', $keyRules, true) ? 'numeric' : (in_array('array', $keyRules, true) ? 'array' : 'string')));
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
