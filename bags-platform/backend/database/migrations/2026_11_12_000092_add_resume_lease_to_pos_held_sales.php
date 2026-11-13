<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HIGH-04 (task9): track non-destructive resume lease on pos_held_sales so
 * dropped network responses or abandoned carts stay recoverable until checkout.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE pos_held_sales ADD COLUMN IF NOT EXISTS resumed_by bigint NULL REFERENCES users(id) ON DELETE SET NULL');
                DB::statement('ALTER TABLE pos_held_sales ADD COLUMN IF NOT EXISTS resumed_at timestamp(0) NULL');
            });

            return;
        }

        Schema::table('pos_held_sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_held_sales', 'resumed_by')) {
                $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_held_sales', 'resumed_at')) {
                $table->timestamp('resumed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE pos_held_sales DROP COLUMN IF EXISTS resumed_at');
                DB::statement('ALTER TABLE pos_held_sales DROP COLUMN IF EXISTS resumed_by');
            });

            return;
        }

        Schema::table('pos_held_sales', function (Blueprint $table): void {
            if (Schema::hasColumn('pos_held_sales', 'resumed_by')) {
                $table->dropConstrainedForeignId('resumed_by');
            }
            if (Schema::hasColumn('pos_held_sales', 'resumed_at')) {
                $table->dropColumn('resumed_at');
            }
        });
    }
};
