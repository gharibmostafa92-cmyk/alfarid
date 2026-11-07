<?php

declare(strict_types=1);

use App\Services\SettingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $keys = [
            'ai_shipping_tools_enabled',
            'ai_storefront_tools_enabled',
            'ai_storefront_place_order_enabled',
        ];

        DB::table('settings')->insertOrIgnore(array_map(fn (string $key): array => [
            'group' => 'ai',
            'key' => $key,
            'value' => '0',
            'type' => 'boolean',
            'created_at' => $now,
            'updated_at' => $now,
        ], $keys));

        DB::afterCommit(fn () => app(SettingService::class)->clearCache());
    }

    public function down(): void
    {
        // Preserve values an administrator may have saved after the migration.
    }
};
