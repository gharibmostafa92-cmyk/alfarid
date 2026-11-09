<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Shared\SettingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    /**
     * @var list<array{group: string, key: string, value: string|null, type: string}>
     */
    private const SETTINGS = [
        ['group' => 'company', 'key' => 'company_name', 'value' => 'Alfarid', 'type' => 'string'],
        ['group' => 'company', 'key' => 'company_email', 'value' => null, 'type' => 'string'],
        ['group' => 'company', 'key' => 'company_phone', 'value' => null, 'type' => 'string'],
        ['group' => 'company', 'key' => 'company_address', 'value' => null, 'type' => 'string'],
        ['group' => 'company', 'key' => 'company_logo', 'value' => null, 'type' => 'string'],
        ['group' => 'company', 'key' => 'commercial_register', 'value' => null, 'type' => 'string'],

        ['group' => 'tax', 'key' => 'tax_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'tax', 'key' => 'tax_number', 'value' => null, 'type' => 'string'],
        ['group' => 'tax', 'key' => 'default_tax_rate', 'value' => '14', 'type' => 'float'],
        ['group' => 'tax', 'key' => 'prices_include_tax', 'value' => '0', 'type' => 'boolean'],

        ['group' => 'numbering', 'key' => 'invoice_prefix', 'value' => 'INV-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'invoice_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'invoice_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'order_prefix', 'value' => 'ORD-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'order_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'order_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'purchase_order_prefix', 'value' => 'PO-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'purchase_order_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'purchase_order_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'quotation_prefix', 'value' => 'QT-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'quotation_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'quotation_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'movement_prefix', 'value' => 'MOV-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'movement_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'movement_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'transfer_prefix', 'value' => 'TRF-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'transfer_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'transfer_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'stocktake_prefix', 'value' => 'STK-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'stocktake_next_number', 'value' => '1000', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'stocktake_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'return_prefix', 'value' => 'RET-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'return_next_number', 'value' => '1001', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'return_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'purchase_request_prefix', 'value' => 'PR-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'purchase_request_next_number', 'value' => '1001', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'purchase_request_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'delivery_prefix', 'value' => 'DLV-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'delivery_next_number', 'value' => '1001', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'delivery_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'supplier_invoice_prefix', 'value' => 'SIN-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'supplier_invoice_next_number', 'value' => '1001', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'supplier_invoice_padding', 'value' => '6', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'supplier_payment_prefix', 'value' => 'SPY-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'supplier_payment_next_number', 'value' => '1001', 'type' => 'integer'],
        ['group' => 'numbering', 'key' => 'supplier_payment_padding', 'value' => '6', 'type' => 'integer'],

        // Return request window in days from delivery. 0 = disabled, -1 = indefinite.
        ['group' => 'orders', 'key' => 'return_window_days', 'value' => '14', 'type' => 'integer'],
        ['group' => 'purchases', 'key' => 'allow_self_approve_purchases', 'value' => '0', 'type' => 'boolean'],

        ['group' => 'ai', 'key' => 'ai_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_daily_token_budget_per_user', 'value' => '1000000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_disabled_tools', 'value' => '[]', 'type' => 'json'],
        ['group' => 'ai', 'key' => 'ai_action_ttl_minutes', 'value' => '10', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_retention_days', 'value' => '180', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_bulk_max_items', 'value' => '25', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_max_tokens_per_cycle', 'value' => '100000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_tool_result_max_chars', 'value' => '16000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_max_concurrent_sessions', 'value' => '10', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_dealer_review_sla_hours', 'value' => '48', 'type' => 'integer'],
        // Credit limit threshold above which changes require explicit confirmation.
        ['group' => 'ai', 'key' => 'ai_credit_limit_confirm_threshold', 'value' => '100000', 'type' => 'integer'],
        // Customer tools and document OCR disabled by default during initial rollout.
        ['group' => 'ai', 'key' => 'ai_customer_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_document_analysis_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_catalog_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_catalog_vision_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_catalog_bulk_price_max_items', 'value' => '200', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_catalog_copywriting_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_pricing_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_promotions_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_pricing_max_items_per_action', 'value' => '200', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_coupon_high_risk_percent', 'value' => '30', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_price_below_cost_policy', 'value' => 'block', 'type' => 'string'],
        ['group' => 'ai', 'key' => 'ai_pricing_vision_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_shipping_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_storefront_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_storefront_place_order_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_inventory_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_inventory_vision_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_inventory_share_cost_with_model', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_inventory_high_risk_value', 'value' => '50000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_inventory_proactive_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_finance_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_wholesale_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_wholesale_receipt_vision_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_quote_high_risk_discount_percent', 'value' => '15', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_quote_high_risk_total', 'value' => '100000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_wholesale_shadow_mode', 'value' => '1', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_wholesale_quote_sla_hours', 'value' => '24', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_wholesale_notice_sla_hours', 'value' => '24', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_wholesale_reminder_weekly_cap', 'value' => '3', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_allow_notice_approval', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_quote_max_discount_percent', 'value' => '5', 'type' => 'integer'],
        ['group' => 'policy', 'key' => 'wholesale_fifo_mode', 'value' => 'strict', 'type' => 'string'],
        ['group' => 'policy', 'key' => 'wholesale_default_payment_terms_days', 'value' => '30', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_finance_wallet_tools_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_finance_receipt_vision_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_finance_proactive_enabled', 'value' => '1', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_finance_high_risk_amount', 'value' => '20000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_wallet_adjust_max_amount', 'value' => '5000', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_finance_share_balances_with_model', 'value' => '1', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_max_steps', 'value' => '6', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_enabled', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_allow_writes', 'value' => '0', 'type' => 'boolean'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_max_risk', 'value' => 'medium', 'type' => 'string'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_disabled_tools', 'value' => '["create_staff_user","create_role","update_role_permissions","delete_role","assign_roles","revoke_user_sessions","update_settings","update_document_numbering"]', 'type' => 'json'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_idle_rotate_hours', 'value' => '12', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_link_token_ttl_minutes', 'value' => '10', 'type' => 'integer'],
        ['group' => 'ai', 'key' => 'ai_whatsapp_pin_required', 'value' => '0', 'type' => 'boolean'],
    ];

    public function run(): void
    {
        $now = now();
        $rows = array_map(
            static fn (array $setting): array => [
                ...$setting,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::SETTINGS,
        );

        Setting::query()->insertOrIgnore($rows);
        DB::afterCommit(fn () => app(SettingService::class)->clearCache());
    }
}
