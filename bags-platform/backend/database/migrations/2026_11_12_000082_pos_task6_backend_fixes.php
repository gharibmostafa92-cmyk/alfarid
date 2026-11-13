<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task6 backend lane: exchange netting audit column, force-close
 * accountability/settlement markers, and the held-sales prune index.
 * Hardened for zero-downtime PostgreSQL rollouts (MEDIUM-05 / task9).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS exchange_credit numeric(14, 2) NOT NULL DEFAULT 0');
                DB::statement('ALTER TABLE pos_shifts ADD COLUMN IF NOT EXISTS force_closed_by bigint NULL REFERENCES users(id) ON DELETE SET NULL');
                DB::statement('ALTER TABLE pos_shifts ADD COLUMN IF NOT EXISTS settled_at timestamp(0) NULL');
            });

            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS pos_held_sales_created_at_index ON pos_held_sales (created_at)');

            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'exchange_credit')) {
                $table->decimal('exchange_credit', 14, 2)->default(0);
            }
        });

        Schema::table('pos_shifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_shifts', 'force_closed_by')) {
                $table->foreignId('force_closed_by')->nullable()
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_shifts', 'settled_at')) {
                $table->timestamp('settled_at')->nullable();
            }
        });

        if (! Schema::hasIndex('pos_held_sales', 'pos_held_sales_created_at_index')) {
            Schema::table('pos_held_sales', function (Blueprint $table): void {
                $table->index('created_at', 'pos_held_sales_created_at_index');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_held_sales_created_at_index');

            $dropSettled = Schema::hasColumn('pos_shifts', 'settled_at') && ! $this->settledAtOwnedBySibling();
            DB::transaction(function () use ($dropSettled): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE pos_shifts DROP COLUMN IF EXISTS force_closed_by');
                if ($dropSettled) {
                    DB::statement('ALTER TABLE pos_shifts DROP COLUMN IF EXISTS settled_at');
                }
                DB::statement('ALTER TABLE orders DROP COLUMN IF EXISTS exchange_credit');
            });

            return;
        }

        if (Schema::hasIndex('pos_held_sales', 'pos_held_sales_created_at_index')) {
            Schema::table('pos_held_sales', function (Blueprint $table): void {
                $table->dropIndex('pos_held_sales_created_at_index');
            });
        }

        if (Schema::hasColumn('pos_shifts', 'force_closed_by')) {
            Schema::table('pos_shifts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('force_closed_by');
            });
        }
        // settled_at is shared with 2026_11_12_000082_add_settled_at_to_pos_shifts;
        // only drop it here when that migration is not the owner.
        if (Schema::hasColumn('pos_shifts', 'settled_at')
            && ! $this->settledAtOwnedBySibling()) {
            Schema::table('pos_shifts', function (Blueprint $table): void {
                $table->dropColumn('settled_at');
            });
        }

        if (Schema::hasColumn('orders', 'exchange_credit')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('exchange_credit');
            });
        }
    }

    private function settledAtOwnedBySibling(): bool
    {
        return collect(
            DB::table('migrations')->pluck('migration')
        )->contains('2026_11_12_000082_add_settled_at_to_pos_shifts');
    }
};
