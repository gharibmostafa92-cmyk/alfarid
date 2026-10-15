<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingService;
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
