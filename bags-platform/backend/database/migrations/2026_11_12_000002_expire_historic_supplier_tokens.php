<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L4: historic supplier tokens were created without expires_at and live forever
     * when SANCTUM_EXPIRATION is empty. Expire them so pruneAndCap can remove them.
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')
            // F22: frozen morph string (SupplierUser has no morph-map alias).
            ->where('tokenable_type', 'App\Models\SupplierUser')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()]);
    }

    public function down(): void
    {
        // Cannot restore the previous non-expiring state safely; no-op.
    }
};
