<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const LEGACY_TYPES = "'opening_balance','pos_sale','pos_return','expense','transfer_in','transfer_out','supplier_payment','customer_receipt'";

    public function up(): void
    {
        ConcurrentIndex::ensure('pos_shifts_one_open_user', "ON pos_shifts (user_id) WHERE status = 'open'", unique: true);
        ConcurrentIndex::ensure('pos_shifts_one_unsettled_drawer', "ON pos_shifts (treasury_id) WHERE status IN ('open','closed_pending_approval')", unique: true);
        $this->replaceCheck('treasuries', 'treasuries_nonnegative_cash', "type = 'bank_account' OR current_balance >= 0");
        $this->replaceCheck('treasury_transactions', 'treasury_transactions_type_check', 'type IN ('.self::LEGACY_TYPES.", 'cash_shortage','cash_overage')");
        $this->replaceCheck('orders', 'orders_payment_status_check', "payment_status IN ('unpaid','paid','failed','refunded','partially_refunded','refund_pending')");
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE treasury_transfers DROP CONSTRAINT IF EXISTS treasury_transfers_separate_approver');
            DB::statement("ALTER TABLE treasury_transfers ADD CONSTRAINT treasury_transfers_separate_approver CHECK (status <> 'approved' OR approved_by <> created_by) NOT VALID");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_treasury_type_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.type IS DISTINCT FROM OLD.type THEN
        RAISE EXCEPTION 'Treasury type is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS treasuries_immutable_type ON treasuries;
CREATE TRIGGER treasuries_immutable_type BEFORE UPDATE OF type ON treasuries
FOR EACH ROW EXECUTE FUNCTION prevent_treasury_type_change();
SQL);
        });
        // The reconciliation index also serves the equality predicates of stale-count checks.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_stale_check_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_branch_id_index');
    }

    public function down(): void
    {
        if (DB::table('treasury_transactions')->whereIn('type', ['cash_shortage', 'cash_overage'])->exists()
            || DB::table('orders')->where('payment_status', 'refund_pending')->exists()) {
            throw new RuntimeException('Resolve pending refunds and preserve cash discrepancy transactions before rollback.');
        }
        DB::statement('DROP TRIGGER IF EXISTS treasuries_immutable_type ON treasuries');
        DB::statement('DROP FUNCTION IF EXISTS prevent_treasury_type_change()');
        DB::statement('ALTER TABLE treasuries DROP CONSTRAINT IF EXISTS treasuries_nonnegative_cash');
        DB::statement('ALTER TABLE treasury_transfers DROP CONSTRAINT IF EXISTS treasury_transfers_separate_approver');
        $this->replaceCheck('treasury_transactions', 'treasury_transactions_type_check', 'type IN ('.self::LEGACY_TYPES.')');
        $this->replaceCheck('orders', 'orders_payment_status_check', "payment_status IN ('unpaid','paid','failed','refunded','partially_refunded')");
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_shifts_one_open_user');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pos_shifts_one_unsettled_drawer');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stock_movements_stale_check_index ON stock_movements (branch_id, location_id, affected_bucket, product_variant_id, id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stock_movements_branch_id_index ON stock_movements (branch_id)');
    }

    private function replaceCheck(string $table, string $constraint, string $expression): void
    {
        DB::transaction(function () use ($table, $constraint, $expression): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint},
                ADD CONSTRAINT {$constraint} CHECK ({$expression}) NOT VALID");
        });
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }
};
