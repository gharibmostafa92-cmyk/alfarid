<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Ai\ToolRegistry;
use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates administrative system settings modifications with optimistic concurrency guarantees.
 *
 * Enforces headquarter branch permissions, AI capabilities authorizations,
 * strict type boundaries, sequence format integrity, and CAS (Compare-And-Swap) expected-state verification.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to update system settings.
     *
     * Requires central headquarters branch scope, and specific AI permission if any ai_* keys are present.
     *
     * @param  BranchAccess  $branchAccess  Branch boundary enforcement service.
     * @return bool True if authorized.
     */
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

    /**
     * Retrieve normalized input data from JSON payload or request parameters.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /**
     * Get the validation rules that apply to the request.
     *
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
            // Return policy: 0 disables returns, -1 allows indefinite returns, positive values define days from delivery.
            'return_window_days' => ['sometimes', 'integer', 'min:-1', 'max:3650'],
            'allow_self_approve_purchases' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_daily_token_budget_per_user' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'ai_disabled_tools' => ['sometimes', 'array'],
            // Disabled tools must reference registered definitions to prevent typos from creating inert configuration.
            'ai_disabled_tools.*' => ['string', 'max:100', Rule::in(array_keys(app(ToolRegistry::class)->definitions()))],
            'ai_action_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'ai_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'ai_bulk_max_items' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ai_max_tokens_per_cycle' => ['sometimes', 'integer', 'min:1000', 'max:10000000'],
            'ai_tool_result_max_chars' => ['sometimes', 'integer', 'min:1024', 'max:200000'],
            'ai_max_concurrent_sessions' => ['sometimes', 'integer', 'min:1', 'max:'.max(1, (int) config('services.gemini.max_concurrent_sessions_cap', 20))],
            'ai_credit_limit_confirm_threshold' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'ai_dealer_review_sla_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'ai_customer_tools_enabled' => ['sometimes', 'boolean'],
            'ai_document_analysis_enabled' => ['sometimes', 'boolean'],
            'ai_catalog_tools_enabled' => ['sometimes', 'boolean'],
            'ai_catalog_vision_enabled' => ['sometimes', 'boolean'],
            'ai_catalog_bulk_price_max_items' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'ai_catalog_copywriting_enabled' => ['sometimes', 'boolean'],
            'ai_inventory_tools_enabled' => ['sometimes', 'boolean'],
            'ai_inventory_vision_enabled' => ['sometimes', 'boolean'],
            'ai_inventory_share_cost_with_model' => ['sometimes', 'boolean'],
            'ai_inventory_high_risk_value' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'ai_inventory_proactive_enabled' => ['sometimes', 'boolean'],
            'ai_finance_tools_enabled' => ['sometimes', 'boolean'],
            'ai_finance_wallet_tools_enabled' => ['sometimes', 'boolean'],
            'ai_finance_receipt_vision_enabled' => ['sometimes', 'boolean'],
            'ai_finance_proactive_enabled' => ['sometimes', 'boolean'],
            'ai_finance_high_risk_amount' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'ai_wallet_adjust_max_amount' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'ai_finance_share_balances_with_model' => ['sometimes', 'boolean'],
            'ai_pricing_tools_enabled' => ['sometimes', 'boolean'],
            'ai_promotions_tools_enabled' => ['sometimes', 'boolean'],
            'ai_pricing_max_items_per_action' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'ai_coupon_high_risk_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ai_price_below_cost_policy' => ['sometimes', 'string', Rule::in(['block', 'warn'])],
            'ai_pricing_vision_enabled' => ['sometimes', 'boolean'],
            'ai_shipping_tools_enabled' => ['sometimes', 'boolean'],
            'ai_storefront_tools_enabled' => ['sometimes', 'boolean'],
            'ai_storefront_place_order_enabled' => ['sometimes', 'boolean'],
            'ai_max_steps' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'ai_whatsapp_enabled' => ['sometimes', 'boolean'],
            'ai_whatsapp_allow_writes' => ['sometimes', 'boolean'],
            'ai_whatsapp_max_risk' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high'])],
            'ai_whatsapp_disabled_tools' => ['sometimes', 'array'],
            'ai_whatsapp_disabled_tools.*' => ['string', 'max:100', Rule::in(array_keys(app(ToolRegistry::class)->definitions()))],
            'ai_whatsapp_idle_rotate_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'ai_whatsapp_link_token_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'ai_whatsapp_pin_required' => ['sometimes', 'boolean'],
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
            // Sequence types supported across both REST and assistant document numbering updates.
            'shift',
            'pos_invoice',
            'expense',
            'treasury_transfer',
            'payment_notice',
        ];
        foreach ($sequenceTypes as $type) {
            $rules["{$type}_prefix"] = ['sometimes', 'nullable', 'string', 'max:30'];
            $rules["{$type}_next_number"] = ['sometimes', 'integer', 'min:1', 'max:999999999'];
            $rules["{$type}_padding"] = ['sometimes', 'integer', 'min:1', 'max:20'];
        }

        // Enforce Compare-And-Swap (CAS) optimistic locking: each changed key must supply its expected current value.
        $expectedRules = ['expected' => ['required', 'array']];
        foreach ($rules as $key => $keyRules) {
            $type = in_array('boolean', $keyRules, true) ? 'boolean'
                : (in_array('integer', $keyRules, true) ? 'integer' : (in_array('numeric', $keyRules, true) ? 'numeric' : (in_array('array', $keyRules, true) ? 'array' : 'string')));
            $expectedRules['expected.'.$key] = ['sometimes', 'nullable', $type];
        }

        return $rules + $expectedRules;
    }

    /**
     * Configure after-validation hooks to enforce strict schema bounds and CAS parity.
     *
     * Rejects unknown payload parameters and ensures the expected-value map strictly mirrors all modified keys.
     *
     * @param  Validator  $validator
     */
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
