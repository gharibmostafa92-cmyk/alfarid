<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public $withinTransaction = false;
    private const INDEXES = [
        'supplier_invoices_attachment_path_idx' => ['supplier_invoices', 'attachment_path'],
        'supplier_deliveries_invoice_file_path_idx' => ['supplier_deliveries', 'invoice_file_path'],
        'wholesale_payment_notices_receipt_file_path_idx' => ['wholesale_payment_notices', 'receipt_file_path'],
        'customer_documents_file_path_idx' => ['customer_documents', 'file_path'],
    ];
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => [$table, $column]) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement("DROP INDEX CONCURRENTLY {$name}");
            }
            if ($index === null || ! $index->indisvalid) {
                DB::statement("CREATE INDEX CONCURRENTLY {$name} ON {$table} ({$column})");
            }
        }
    }
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
