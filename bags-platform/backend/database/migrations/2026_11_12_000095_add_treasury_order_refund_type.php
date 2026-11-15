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

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement(<<<'SQL'
                ALTER TABLE treasury_transactions
                  DROP CONSTRAINT IF EXISTS treasury_transactions_type_sign_check,
                  ADD CONSTRAINT treasury_transactions_type_sign_check CHECK (
                    (type IN ('opening_balance','pos_sale','expense_reversal','transfer_in','customer_receipt','cash_overage') AND amount > 0)
                    OR (type IN ('pos_return','order_refund','expense','transfer_out','supplier_payment','customer_receipt_reversal','cash_shortage') AND amount < 0)
                  ) NOT VALID
                SQL);
        });
        DB::statement('ALTER TABLE treasury_transactions VALIDATE CONSTRAINT treasury_transactions_type_sign_check');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS gateway_refunded_amount numeric(14, 2)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Retain support for immutable refunds already posted to the ledger.
    }
};
