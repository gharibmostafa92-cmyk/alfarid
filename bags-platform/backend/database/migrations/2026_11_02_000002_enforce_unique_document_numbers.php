<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const OWNER_MARK = 'Created by enforce_unique_document_numbers';

    /** @var array<string, array{0: string, 1: string}> */
    private const TARGETS = [
        'order' => ['orders', 'order_number'],
        'purchase_order' => ['purchase_orders', 'po_number'],
        'quotation' => ['quotations', 'quotation_number'],
        'transfer' => ['stock_transfers', 'transfer_number'],
        'stocktake' => ['stocktakes', 'stocktake_number'],
        'return' => ['return_requests', 'return_number'],
        'purchase_request' => ['purchase_requests', 'request_number'],
        'delivery' => ['supplier_deliveries', 'delivery_number'],
        'supplier_invoice' => ['supplier_invoices', 'invoice_number'],
        'supplier_payment' => ['supplier_payments', 'payment_number'],
        'shift' => ['pos_shifts', 'shift_number'],
        'expense' => ['expenses', 'expense_number'],
        'treasury_transfer' => ['treasury_transfers', 'transfer_number'],
        'payment_notice' => ['wholesale_payment_notices', 'notice_number'],
        'movement' => ['stock_movements', 'reference_number'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as [$table, $column]) {
            $index = $table.'_'.$column.'_docnum_unique';
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_index i
                 JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[0]
                 WHERE t.oid = to_regclass(?)
                   AND i.indisunique
                   AND i.indisvalid
                   AND i.indnkeyatts = 1
                   AND i.indpred IS NULL
                   AND i.indexprs IS NULL
                   AND a.attname = ?
                 LIMIT 1',
                [$table, $column],
                false,
            );
            if ($exists !== null) {
                continue;
            }

            if (ConcurrentIndex::ensure($index, "ON {$table} ({$column})", unique: true)) {
                DB::statement("COMMENT ON INDEX {$index} IS '".self::OWNER_MARK."'");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as [$table, $column]) {
            $index = $table.'_'.$column.'_docnum_unique';
            $owner = DB::scalar("SELECT obj_description(to_regclass(?), 'pg_class')", [$index], false);
            if ($owner === self::OWNER_MARK) {
                ConcurrentIndex::drop($index);
            }
        }
    }
};
