<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task6 backend lane: exchange netting audit column, force-close
 * accountability/settlement markers, and the held-sales prune index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'exchange_credit')) {
                $table->decimal('exchange_credit', 14, 2)->default(0)->after('card_amount');
            }
        });

        Schema::table('pos_shifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_shifts', 'force_closed_by')) {
                $table->foreignId('force_closed_by')->nullable()->after('approved_by')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_shifts', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('force_closed_by');
            }
        });

        Schema::table('pos_held_sales', function (Blueprint $table): void {
            $table->index('created_at', 'pos_held_sales_created_at_index');
        });
    }

    public function down(): void
    {
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
            \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')
        )->contains('2026_11_12_000082_add_settled_at_to_pos_shifts');
    }
};
