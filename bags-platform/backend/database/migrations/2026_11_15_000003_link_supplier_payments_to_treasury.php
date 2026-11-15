<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_payments', 'treasury_id')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                // Existing records predate treasury integration and must be reconciled separately.
                $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->restrictOnDelete();
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_sign_check, ADD CONSTRAINT treasury_transactions_type_sign_check CHECK ((type IN ('opening_balance','pos_sale','expense_reversal','transfer_in','customer_receipt','cash_overage','supplier_payment_reversal') AND amount > 0) OR (type IN ('pos_return','order_refund','expense','transfer_out','supplier_payment','customer_receipt_reversal','cash_shortage') AND amount < 0))");
        }
    }

    public function down(): void
    {
        if (DB::table('supplier_payments')->whereNotNull('treasury_id')->exists()
            || DB::table('treasury_transactions')->where('type', 'supplier_payment_reversal')->exists()) {
            throw new RuntimeException('لا يمكن التراجع عن ربط دفعات المورد بالخزينة مع وجود حركات مرتبطة.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_sign_check, ADD CONSTRAINT treasury_transactions_type_sign_check CHECK ((type IN ('opening_balance','pos_sale','expense_reversal','transfer_in','customer_receipt','cash_overage') AND amount > 0) OR (type IN ('pos_return','order_refund','expense','transfer_out','supplier_payment','customer_receipt_reversal','cash_shortage') AND amount < 0))");
        }
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('treasury_id'));
    }
};
