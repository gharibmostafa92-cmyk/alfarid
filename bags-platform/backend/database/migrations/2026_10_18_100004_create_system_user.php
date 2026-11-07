<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions a dedicated system user for automated background stock and order lifecycle movements
 * so audit trails accurately attribute actions to the system rather than an arbitrary admin account.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Email read from configuration to remain synchronized with OrderService system queries
        $email = (string) config('orders.system_user_email', 'system@alfarid.local');

        DB::table('users')->insertOrIgnore([
            'name' => 'System',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            // Disabled to disallow interactive logins; used strictly for automated audit logs
            'is_active' => false,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->delete();
    }
};
