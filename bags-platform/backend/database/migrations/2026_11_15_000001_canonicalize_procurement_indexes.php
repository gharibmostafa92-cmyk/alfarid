<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Replaces the two supplier index names created by feature migrations with the
 * canonical Laravel column-suffixed names, and restores the missing procurement
 * foreign-key indexes. Drop/create run CONCURRENTLY outside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::drop('supplier_payments_supplier_date_index');
        ConcurrentIndex::drop('supplier_invoices_supplier_status_index');

        ConcurrentIndex::ensure(
            'supplier_payments_supplier_id_payment_date_index',
            'ON supplier_payments (supplier_id, payment_date)',
        );
        ConcurrentIndex::ensure(
            'supplier_invoices_supplier_id_status_index',
            'ON supplier_invoices (supplier_id, status)',
        );
        ConcurrentIndex::ensure(
            'purchase_request_items_purchase_request_id_index',
            'ON purchase_request_items (purchase_request_id)',
        );
        ConcurrentIndex::ensure(
            'purchase_orders_purchase_request_id_index',
            'ON purchase_orders (purchase_request_id)',
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_payments_supplier_id_payment_date_index');
        ConcurrentIndex::drop('supplier_invoices_supplier_id_status_index');
        ConcurrentIndex::drop('purchase_request_items_purchase_request_id_index');
        ConcurrentIndex::drop('purchase_orders_purchase_request_id_index');

        ConcurrentIndex::ensure(
            'supplier_payments_supplier_date_index',
            'ON supplier_payments (supplier_id, payment_date)',
        );
        ConcurrentIndex::ensure(
            'supplier_invoices_supplier_status_index',
            'ON supplier_invoices (supplier_id, status)',
        );
    }
};
