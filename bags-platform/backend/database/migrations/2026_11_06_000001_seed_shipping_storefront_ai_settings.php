<?php

declare(strict_types=1);

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

        DB::afterCommit(fn () => \Illuminate\Support\Facades\Cache::forever('app_settings_static_version', (string) \Illuminate\Support\Str::uuid()));
        DB::afterCommit(fn () => \Illuminate\Support\Facades\Cache::forget('app_settings_static'));
    }

    public function down(): void
    {
        // Preserve values an administrator may have saved after the migration.
    }
};
