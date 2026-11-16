<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('message_templates')->updateOrInsert(
            ['name' => 'customer_statement', 'language' => 'ar'],
            [
                'body' => 'كشف حسابك الشهري لشهر {{month}} جاهز في بوابة الجملة.',
                'variables' => json_encode(['month']),
                'status' => 'approved',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('message_templates')
            ->where('name', 'customer_statement')
            ->where('language', 'ar')
            ->delete();
    }
};
