<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('settings')->insertOrIgnore([
            ['group' => 'numbering', 'key' => 'payment_notice_prefix', 'value' => 'WPN-', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'payment_notice_next_number', 'value' => '1001', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'payment_notice_padding', 'value' => '6', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        // L1: المفاتيح المزروعة في up() فقط — بلا wildcard.
        DB::table('settings')->whereIn('key', [
            'payment_notice_prefix', 'payment_notice_next_number', 'payment_notice_padding',
        ])->delete();
    }
};
