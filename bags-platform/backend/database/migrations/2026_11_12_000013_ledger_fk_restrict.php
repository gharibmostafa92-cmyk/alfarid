<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
/**
 * F5/F10: deleting a referenced row must not cascade-erase ledger evidence.
 * H-5: add the new FK NOT VALID under a temp name, swap, then VALIDATE outside the
 * swap transaction (SHARE UPDATE EXCLUSIVE: orders/users writes keep flowing).
 * Rows already satisfied the old FK on the same column, so VALIDATE cannot fail.
 */
return new class extends Migration
{
    public $withinTransaction = false;
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $this->replace('treasury_transfers', 'approved_by', 'users', 'treasury_transfers_approved_by_foreign', 'RESTRICT');
        $this->replace('wholesale_payment_allocations', 'notice_id', 'wholesale_payment_notices', 'wholesale_payment_allocations_notice_id_foreign', 'RESTRICT');
        $this->replace('wholesale_payment_allocations', 'order_id', 'orders', 'wholesale_payment_allocations_order_id_foreign', 'RESTRICT');
    }
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $this->replace('treasury_transfers', 'approved_by', 'users', 'treasury_transfers_approved_by_foreign', 'SET NULL');
        $this->replace('wholesale_payment_allocations', 'notice_id', 'wholesale_payment_notices', 'wholesale_payment_allocations_notice_id_foreign', 'CASCADE');
        $this->replace('wholesale_payment_allocations', 'order_id', 'orders', 'wholesale_payment_allocations_order_id_foreign', 'CASCADE');
    }
    private function replace(string $table, string $column, string $references, string $constraint, string $onDelete): void
    {
        $code = ['RESTRICT' => 'r', 'SET NULL' => 'n', 'CASCADE' => 'c'][$onDelete];
        $temp = $constraint.'_new';
        DB::transaction(function () use ($table, $column, $references, $constraint, $onDelete, $code, $temp): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            $current = DB::selectOne('SELECT confdeltype FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$constraint, $table]);
            if ($current === null) {
                throw new RuntimeException("Missing foreign key {$constraint} on {$table}; refusing to deploy without the ledger invariant.");
            }
            if ($current->confdeltype === $code) {
                return;
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$temp}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$temp} FOREIGN KEY ({$column}) REFERENCES {$references} (id) ON DELETE {$onDelete} NOT VALID");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$temp} TO {$constraint}");
        });
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }
};
