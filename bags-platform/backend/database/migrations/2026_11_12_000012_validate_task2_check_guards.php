<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Validate the NOT VALID check guards added in 2026_11_12_000004.
     * VALIDATE takes SHARE UPDATE EXCLUSIVE (blocks DDL, not reads/writes).
     * Run in a maintenance window if any table holds violating legacy rows —
     * VALIDATE fails loudly instead of silently skipping, by design.
     */
    public function up(): void
    {
        foreach ([
            ['orders', 'orders_idempotency_scoped'],
            ['coupons', 'coupons_usage_within_limit'],
            ['treasury_transactions', 'treasury_transactions_amount_nonzero'],
            ['treasury_transfers', 'treasury_transfers_amount_positive'],
            ['treasury_transfers', 'treasury_transfers_distinct_treasuries'],
            ['expenses', 'expenses_amount_positive'],
            ['treasury_transfers', 'treasury_transfers_approver_required'],
            ['wholesale_payment_allocations', 'wholesale_payment_allocations_amount_positive'],
        ] as [$table, $constraint]) {
            if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)', [$constraint, $table]) !== null) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        }
    }

    public function down(): void
    {
        // VALIDATE is irreversible by design (it only scans); nothing to undo.
    }
};
