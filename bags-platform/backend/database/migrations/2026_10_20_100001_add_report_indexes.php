<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Indexes to accelerate financial reporting: supplier invoice aging queries and stock movement COGS date aggregations.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('supplier_invoices_supplier_status_index', 'ON supplier_invoices (supplier_id, status)');
        ConcurrentIndex::ensure('supplier_invoices_due_date_index', 'ON supplier_invoices (due_date)');
        ConcurrentIndex::ensure('stock_movements_type_created_index', 'ON stock_movements (type, created_at)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_type_created_index');
        ConcurrentIndex::drop('supplier_invoices_due_date_index');
        ConcurrentIndex::drop('supplier_invoices_supplier_status_index');
    }
};
