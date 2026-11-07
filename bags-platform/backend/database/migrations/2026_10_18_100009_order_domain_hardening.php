<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order domain hardening:
 * - Scopes idempotency keys to client sender identity (idempotency_scope, idempotency_key).
 * - Denormalizes normalized guest phone on orders for reliable deduplication and abuse limits.
 * - Restricts coupon deletions if historical usage records exist.
 * - Adds index on orders(coupon_id, status) for rapid active reservation counts.
 * - Enforces inactive status on the automated system user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'idempotency_scope')) {
                $table->string('idempotency_scope', 191)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('orders', 'guest_phone_normalized')) {
                $table->string('guest_phone_normalized', 32)->nullable()->after('guest_phone');
            }
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(['idempotency_scope', 'idempotency_key']);
            $table->index('guest_phone_normalized');
            $table->index(['coupon_id', 'status']);
        });

        // Retain coupon usage history when coupons are retired (restrict deletion)
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->foreign('coupon_id')->references('id')->on('coupons')->restrictOnDelete();
        });

        // Deactivate system user to disallow interactive login sessions
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', (string) config('orders.system_user_email', 'system@alfarid.local'))
            ->update(['is_active' => true]);

        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_scope', 'idempotency_key']);
            $table->dropIndex(['guest_phone_normalized']);
            $table->dropIndex(['coupon_id', 'status']);
            $table->dropColumn('idempotency_scope');
            $table->dropColumn('guest_phone_normalized');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('idempotency_key');
        });
    }
};
