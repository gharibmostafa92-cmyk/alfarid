<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F5: treasury ledger uniqueness and sign-per-type guards.
 *
 * A duplicate (reference, type, treasury) row is never legitimate: expenses,
 * reversals, transfer pairs, and shift settlements all post exactly one row
 * per document. The sign guard makes the ledger type self-describing so a
 * negative "expense" or a positive "expense_reversal" cannot silently invert
 * the balance. Both are added NOT VALID so deployment is not blocked by
 * legacy rows; new writes are enforced immediately.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const POSITIVE_TYPES = "'opening_balance','pos_sale','expense_reversal','transfer_in','customer_receipt','cash_overage'";

    private const NEGATIVE_TYPES = "'pos_return','expense','transfer_out','supplier_payment','customer_receipt_reversal','cash_shortage'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::ensure(
            'treasury_transactions_reference_unique',
            'ON treasury_transactions (reference_type, reference_id, type, treasury_id) WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL',
            unique: true,
        );

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            $positive = self::POSITIVE_TYPES;
            $negative = self::NEGATIVE_TYPES;
            DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_sign_check');
            DB::statement(
                "ALTER TABLE treasury_transactions ADD CONSTRAINT treasury_transactions_type_sign_check CHECK ("
                ."(type IN ({$positive}) AND amount > 0) OR (type IN ({$negative}) AND amount < 0)"
                .') NOT VALID'
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_sign_check');
        ConcurrentIndex::drop('treasury_transactions_reference_unique');
    }
};
