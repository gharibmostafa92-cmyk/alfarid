<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-15: coupon usage limits were counted on customer_id (live rows only for
 * phones), so a delete + re-register with the same phone reset
 * usage_limit_per_customer. Store the normalized phone on every usage row
 * and count on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->after('guest_phone_normalized');
            $table->index(['coupon_id', 'phone_normalized'], 'coupon_usages_coupon_id_phone_index');
        });

        // Guest rows already carry the normalized phone; registered rows copy
        // the normalized customer phone stored at write time.
        DB::statement(<<<'SQL'
            UPDATE coupon_usages
            SET phone_normalized = guest_phone_normalized
            WHERE phone_normalized IS NULL AND guest_phone_normalized IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            UPDATE coupon_usages AS u
            SET phone_normalized = c.phone
            FROM customers AS c
            WHERE u.phone_normalized IS NULL AND u.customer_id IS NOT NULL AND c.id = u.customer_id
            SQL);
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropIndex('coupon_usages_coupon_id_phone_index');
            $table->dropColumn('phone_normalized');
        });
    }
};
