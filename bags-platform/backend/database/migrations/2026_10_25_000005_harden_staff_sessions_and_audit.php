<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->selectRaw('LOWER(name), guard_name')->groupByRaw('LOWER(name), guard_name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Case-insensitive duplicate role names must be resolved before applying the role-name index.');
        }
        DB::statement('CREATE UNIQUE INDEX roles_name_guard_ci_unique ON roles (LOWER(name), guard_name)');
        Schema::table('staff_audit_logs', fn (Blueprint $table) => $table->index(['actor_id', 'created_at']));
        // Expire historical staff tokens too; never alter customer/supplier token lifetimes.
        $minutes = max(1, (int) config('auth.staff_token_minutes', 120));
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->update([
            'expires_at' => DB::raw("LEAST(COALESCE(expires_at, created_at + INTERVAL '{$minutes} minutes'), created_at + INTERVAL '{$minutes} minutes')"),
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS roles_name_guard_ci_unique');
        Schema::table('staff_audit_logs', fn (Blueprint $table) => $table->dropIndex(['actor_id', 'created_at']));
        // Expired credentials are deliberately not revived by rollback.
    }
};
