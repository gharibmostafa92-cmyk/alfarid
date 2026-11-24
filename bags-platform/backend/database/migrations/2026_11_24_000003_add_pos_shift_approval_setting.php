<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'group' => 'pos',
            'key' => 'pos_shift_requires_approval',
            'value' => '1',
            'type' => 'boolean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->invalidateSettingsCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'pos_shift_requires_approval')->delete();

        $this->invalidateSettingsCache();
    }

    private function invalidateSettingsCache(): void
    {
        DB::afterCommit(fn () => Cache::forever('app_settings_static_version', (string) Str::uuid()));
    }
};
