<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
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

        // F01: enforce at most one account-level opening_balance entry per treasury
        // while still allowing per-shift opening_balance entries (reference_type='pos_shift').
        ConcurrentIndex::ensure(
            'treasury_transactions_opening_balance_unique',
            "ON treasury_transactions (treasury_id) WHERE type = 'opening_balance' AND reference_type IS NULL AND reference_id IS NULL",
            unique: true,
        );

        // F01: validate treasury_transactions_type_sign_check added NOT VALID in 000068.
        if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'treasury_transactions'::regclass AND conname = 'treasury_transactions_type_sign_check'") !== null) {
            DB::statement('ALTER TABLE treasury_transactions VALIDATE CONSTRAINT treasury_transactions_type_sign_check');
        }

        // F01: ensure legacy NOT VALID separate_approver is gone and approver_required is present and validated.
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE treasury_transfers DROP CONSTRAINT IF EXISTS treasury_transfers_separate_approver');
            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'treasury_transfers'::regclass AND conname = 'treasury_transfers_approver_required'") === null) {
                DB::statement("ALTER TABLE treasury_transfers ADD CONSTRAINT treasury_transfers_approver_required CHECK (status <> 'approved' OR (approved_by IS NOT NULL AND approved_by <> created_by)) NOT VALID");
            }
        });
        DB::statement('ALTER TABLE treasury_transfers VALIDATE CONSTRAINT treasury_transfers_approver_required');

        // F10: composite index for per-branch dead-stock LATERAL lookup.
        ConcurrentIndex::ensure(
            'stock_movements_inventory_last_activity_idx',
            "ON stock_movements (product_variant_id, branch_id, created_at DESC) WHERE type NOT IN ('reserve', 'release_reserve')",
        );

        // F03: backfill any returned orders missing returned_at from status history or updated_at.
        DB::statement(<<<'SQL'
            UPDATE orders o
            SET returned_at = COALESCE(
                (
                    SELECT MAX(h.created_at)
                    FROM order_status_histories h
                    WHERE h.order_id = o.id
                      AND h.to_status = 'returned'
                ),
                o.updated_at
            )
            WHERE o.status = 'returned'
              AND o.returned_at IS NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('stock_movements_inventory_last_activity_idx');
        ConcurrentIndex::drop('treasury_transactions_opening_balance_unique');
    }
};
