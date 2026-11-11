<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
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
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }
    }

    public function down(): void
    {
        // Validation does not change which writes the constraints accept.
    }
};
