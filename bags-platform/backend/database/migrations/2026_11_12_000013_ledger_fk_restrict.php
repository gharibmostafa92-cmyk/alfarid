<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F5/F10: deleting a referenced row must not cascade-erase ledger evidence.
     * - treasury_transfers.approved_by: SET NULL erases who approved a money
     *   movement. Switch to RESTRICT so the approver account cannot vanish
     *   while its approvals exist (transfer first, then delete the user).
     * - wholesale_payment_allocations.notice_id/order_id: CASCADE would silently
     *   un-allocate money when a notice or order row is removed. Switch both
     *   to RESTRICT; deletions must reverse allocations explicitly first.
     * Runs in a transaction with a short local lock_timeout so a hot table
     * fails fast instead of queueing behind ACCESS EXCLUSIVE.
     */
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '10s'");
        $this->restrict('treasury_transfers', 'approved_by', 'users', 'treasury_transfers_approved_by_foreign');
        $this->restrict('wholesale_payment_allocations', 'notice_id', 'wholesale_payment_notices', 'wholesale_payment_allocations_notice_id_foreign');
        $this->restrict('wholesale_payment_allocations', 'order_id', 'orders', 'wholesale_payment_allocations_order_id_foreign');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('ALTER TABLE treasury_transfers DROP CONSTRAINT IF EXISTS treasury_transfers_approved_by_foreign');
        DB::statement('ALTER TABLE treasury_transfers ADD CONSTRAINT treasury_transfers_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE wholesale_payment_allocations DROP CONSTRAINT IF EXISTS wholesale_payment_allocations_notice_id_foreign');
        DB::statement('ALTER TABLE wholesale_payment_allocations ADD CONSTRAINT wholesale_payment_allocations_notice_id_foreign FOREIGN KEY (notice_id) REFERENCES wholesale_payment_notices (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE wholesale_payment_allocations DROP CONSTRAINT IF EXISTS wholesale_payment_allocations_order_id_foreign');
        DB::statement('ALTER TABLE wholesale_payment_allocations ADD CONSTRAINT wholesale_payment_allocations_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
    }

    private function restrict(string $table, string $column, string $references, string $constraint): void
    {
        $exists = DB::selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ?', [$constraint]);
        if ($exists === null) {
            return;
        }
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES {$references} (id) ON DELETE RESTRICT");
    }
};
