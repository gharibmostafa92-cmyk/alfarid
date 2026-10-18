<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة 9 — زرع إعدادات المرتجعات في قواعد قائمة (insertOrIgnore):
 * ترقيم RET-001001 ونافذة الاسترجاع بالأيام من تاريخ التسليم.
 * نفس القيم موجودة في SettingSeeder للقواعد الجديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore([
            ['group' => 'numbering', 'key' => 'return_prefix', 'value' => 'RET-', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'return_next_number', 'value' => '1001', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'return_padding', 'value' => '6', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'orders', 'key' => 'return_window_days', 'value' => '14', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'return_prefix', 'return_next_number', 'return_padding', 'return_window_days',
        ])->delete();
    }
};
