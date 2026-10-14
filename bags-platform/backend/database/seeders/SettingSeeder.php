<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

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
        ['group' => 'numbering', 'key' => 'quotation_prefix', 'value' => 'QT-', 'type' => 'string'],
        ['group' => 'numbering', 'key' => 'quotation_next_number', 'value' => '1000', 'type' => 'integer'],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $setting) {
            Setting::query()->firstOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }
}
