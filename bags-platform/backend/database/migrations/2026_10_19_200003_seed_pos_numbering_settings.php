<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defs = [
            ['shift', 'SH-', '1001', '6'],
            ['pos_invoice', 'POS-', '1001', '7'],
            ['expense', 'EXP-', '1001', '6'],
            ['treasury_transfer', 'TRT-', '1001', '6'],
        ];

        $rows = [];
        foreach ($defs as [$type, $prefix, $next, $padding]) {
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_prefix", 'value' => $prefix, 'type' => 'string', 'created_at' => $now, 'updated_at' => $now];
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_next_number", 'value' => $next, 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now];
            $rows[] = ['group' => 'numbering', 'key' => "{$type}_padding", 'value' => $padding, 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('settings')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'like', 'shift\_%')
            ->orWhere('key', 'like', 'pos\_invoice\_%')
            ->orWhere('key', 'like', 'expense\_%')
            ->orWhere('key', 'like', 'treasury\_transfer\_%')
            ->delete();
    }
};
