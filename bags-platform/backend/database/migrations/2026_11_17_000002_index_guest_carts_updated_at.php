<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up the abandoned-guest cart prune (customer_id IS NULL, updated_at older than the window).
 * CREATE INDEX CONCURRENTLY cannot run inside a transaction on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS carts_guest_updated_at_idx ON carts (updated_at) WHERE customer_id IS NULL');

            return;
        }

        Schema::table('carts', function (Blueprint $table): void {
            $table->index('updated_at', 'carts_guest_updated_at_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS carts_guest_updated_at_idx');

            return;
        }

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex('carts_guest_updated_at_idx');
        });
    }
};
