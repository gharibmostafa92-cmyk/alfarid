<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * F3/F5/F8/F10/F12: add NOT VALID check guards (no table scan, no long locks).
     * Run VALIDATE CONSTRAINT in a follow-up deploy step per table.
     */
    public function up(): void
    {
        // F8: idempotency key requires a scope (NULL scope bypasses the unique index).
        $this->addCheck('orders', 'orders_idempotency_scoped', '(idempotency_key IS NULL OR idempotency_scope IS NOT NULL)');
        // F12: coupon usage cap enforced by the database, not just the app lock.
        $this->addCheck('coupons', 'coupons_usage_within_limit', '(usage_limit IS NULL OR used_count <= usage_limit)');
        // F3: treasury movement sanity.
        $this->addCheck('treasury_transactions', 'treasury_transactions_amount_nonzero', '(amount <> 0)');
        $this->addCheck('treasury_transfers', 'treasury_transfers_amount_positive', '(amount > 0)');
        $this->addCheck('treasury_transfers', 'treasury_transfers_distinct_treasuries', '(from_treasury_id <> to_treasury_id)');
        $this->addCheck('expenses', 'expenses_amount_positive', '(amount > 0)');
        // F5: an approved transfer must record a distinct approver.
        $this->addCheck('treasury_transfers', 'treasury_transfers_approver_required', "(status <> 'approved' OR (approved_by IS NOT NULL AND approved_by <> created_by))");
        // F10: allocation amounts must be positive.
        $this->addCheck('wholesale_payment_allocations', 'wholesale_payment_allocations_amount_positive', '(amount > 0)');
    }

    public function down(): void
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
            DB::transaction(function () use ($table, $constraint): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            });
        }
    }

    private function addCheck(string $table, string $constraint, string $expr): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                PERFORM set_config('lock_timeout', '3s', true);
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = '{$constraint}' AND conrelid = '{$table}'::regclass
                ) THEN
                    ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK {$expr} NOT VALID;
                END IF;
            END
            \$\$;
        SQL);
    }
};
