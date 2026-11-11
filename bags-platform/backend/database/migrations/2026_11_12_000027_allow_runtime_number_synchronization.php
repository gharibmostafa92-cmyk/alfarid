<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEQUENCES = <<<'SQL'
        stock_movement_number_seq,
        document_order_number_seq, document_purchase_order_number_seq, document_quotation_number_seq,
        document_transfer_number_seq, document_stocktake_number_seq, document_return_number_seq,
        document_purchase_request_number_seq, document_delivery_number_seq, document_supplier_invoice_number_seq,
        document_supplier_payment_number_seq, document_shift_number_seq, document_pos_invoice_number_seq,
        document_expense_number_seq, document_treasury_transfer_number_seq, document_payment_notice_number_seq
        SQL;

    public function up(): void
    {
        DB::statement('GRANT UPDATE ON SEQUENCE '.self::SEQUENCES.' TO alfarid_app');
    }

    public function down(): void
    {
        DB::statement('REVOKE UPDATE ON SEQUENCE '.self::SEQUENCES.' FROM alfarid_app');
    }
};
