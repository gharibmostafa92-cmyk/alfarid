<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة 10 — زرع ترقيمات مستندات المشتريات في قواعد قائمة (insertOrIgnore):
 * طلب شراء داخلي PR-، إشعار توريد DLV-، فاتورة مورد SIN-، دفعة مورد SPY-.
 * (ترقيم أمر الشراء PO- موجود منذ المرحلة 1.) نفس القيم في SettingSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defs = [
            ['purchase_request', 'PR-', '1001'],
            ['delivery', 'DLV-', '1001'],
            ['supplier_invoice', 'SIN-', '1001'],
            ['supplier_payment', 'SPY-', '1001'],
        ];
        $rows = [];
        foreach ($defs as [$type, $prefix, $next]) {
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_prefix", 'value' => $prefix, 'type' => 'string', 'created_at' => $now, 'updated_at' => $now];
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_next_number", 'value' => $next, 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now];
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_padding", 'value' => '6', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('settings')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        // L1: قائمة مفاتيح صريحة — wildcard بيقع على إعدادات تانية
        // (delivery_fee، approved_expenses...) ملهاش علاقة بالترقيم.
        $keys = [];
        foreach (['purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment'] as $type) {
            $keys[] = "{$type}_prefix";
            $keys[] = "{$type}_next_number";
            $keys[] = "{$type}_padding";
        }
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
