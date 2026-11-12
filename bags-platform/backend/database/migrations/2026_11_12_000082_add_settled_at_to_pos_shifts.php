<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F03 (task5): record an explicit settlement timestamp on pos_shifts so zero-cash,
 * zero-variance shifts cannot be settled twice across retries.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE pos_shifts ADD COLUMN IF NOT EXISTS settled_at timestamp(0) NULL');
                DB::statement("UPDATE pos_shifts SET settled_at = COALESCE(approved_at, updated_at, NOW()) WHERE status = 'approved' AND settled_at IS NULL");
            });

            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn('pos_shifts', 'settled_at')) {
            \Illuminate\Support\Facades\Schema::table('pos_shifts', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->timestamp('settled_at')->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE pos_shifts DROP COLUMN IF EXISTS settled_at');
            });

            return;
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_shifts', 'settled_at')) {
            \Illuminate\Support\Facades\Schema::table('pos_shifts', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropColumn('settled_at');
            });
        }
    }
};
