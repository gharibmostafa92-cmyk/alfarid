<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Earlier releases could record the validation migration as applied
        // even when legacy rows violated one of these checks.
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
            $exists = DB::scalar(
                'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass AND NOT convalidated',
                [$constraint, $table]
            );
            if ($exists === null) {
                continue;
            }

            DB::transaction(function () use ($table, $constraint): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            });
        }
    }

    public function down(): void
    {
        // Validation does not change which writes the constraints accept.
    }
};
