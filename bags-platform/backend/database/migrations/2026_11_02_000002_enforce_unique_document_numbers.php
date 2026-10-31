<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
            $index = $table.'_'.$column.'_unique';
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_index i
                 JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[0]
                 WHERE t.oid = to_regclass(?)
                   AND i.indisunique
                   AND i.indnkeyatts = 1
                   AND a.attname = ?
                 LIMIT 1',
                [$table, $column],
            );
            if ($exists !== null) {
                continue;
            }

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s" ("%s")',
                $index,
                $table,
                $column,
            ));
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as [$table, $column]) {
            $index = $table.'_'.$column.'_unique';
            // Some uniques pre-date this migration as table CONSTRAINTS (e.g.
            // orders.order_number): their backing index cannot be DROPped —
            // and must not be, since this migration did not create it.
            $backed = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conindid = (SELECT oid FROM pg_class WHERE relname = ?)',
                [$index],
            );
            if ($backed === null) {
                DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $index));
            }
        }
    }
};
