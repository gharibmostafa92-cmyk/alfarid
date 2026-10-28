<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L6: نوع دفتر مستقل لعكس المصروف (expense_reversal) حتى لا تُقرأ
 * العكوس الموجبة كمصروفات في أي تجميع حسب النوع.
 */
return new class extends Migration
{
    private const TYPES = "'opening_balance','pos_sale','pos_return','expense','expense_reversal','transfer_in','transfer_out','supplier_payment','customer_receipt','cash_shortage','cash_overage'";

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_check');
            DB::statement('ALTER TABLE treasury_transactions ADD CONSTRAINT treasury_transactions_type_check CHECK (type IN ('.self::TYPES.'))');
        });
    }

    public function down(): void
    {
        if (DB::table('treasury_transactions')->where('type', 'expense_reversal')->exists()) {
            throw new RuntimeException('Resolve expense_reversal transactions before rollback.');
        }
        DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT treasury_transactions_type_check');
        DB::statement("ALTER TABLE treasury_transactions ADD CONSTRAINT treasury_transactions_type_check CHECK (type IN ('opening_balance','pos_sale','pos_return','expense','transfer_in','transfer_out','supplier_payment','customer_receipt','cash_shortage','cash_overage'))");
    }
};
