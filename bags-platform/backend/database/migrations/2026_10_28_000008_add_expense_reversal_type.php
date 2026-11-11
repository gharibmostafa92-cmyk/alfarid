<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated ledger type for expense reversals (expense_reversal) to distinguish voided transactions from standard expense outflows.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TYPES = "'opening_balance','pos_sale','pos_return','expense','expense_reversal','transfer_in','transfer_out','supplier_payment','customer_receipt','cash_shortage','cash_overage'";

    public function up(): void
    {
        $this->replaceTypes(self::TYPES);
    }

    public function down(): void
    {
        if (DB::table('treasury_transactions')->where('type', 'expense_reversal')->exists()) {
            throw new RuntimeException('Resolve expense_reversal transactions before rollback.');
        }
        $this->replaceTypes(str_replace("'expense_reversal',", '', self::TYPES));
    }

    private function replaceTypes(string $types): void
    {
        DB::transaction(function () use ($types): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement("ALTER TABLE treasury_transactions
                DROP CONSTRAINT IF EXISTS treasury_transactions_type_check,
                ADD CONSTRAINT treasury_transactions_type_check CHECK (type IN ({$types})) NOT VALID");
        });
        DB::statement('ALTER TABLE treasury_transactions VALIDATE CONSTRAINT treasury_transactions_type_check');
    }
};
