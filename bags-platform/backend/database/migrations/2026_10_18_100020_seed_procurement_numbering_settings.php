<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds document numbering settings for procurement documents (PR-, DLV-, SIN-, and SPY-).
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
        // Delete explicit numbering keys to avoid unintended deletion of unrelated settings sharing prefixes.
        $keys = [];
        foreach (['purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment'] as $type) {
            $keys[] = "{$type}_prefix";
            $keys[] = "{$type}_next_number";
            $keys[] = "{$type}_padding";
        }
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
