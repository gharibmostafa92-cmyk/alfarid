<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Creates foreign key and statement lookup indexes CONCURRENTLY outside a transaction
 * to prevent ACCESS EXCLUSIVE table locks during production migrations.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('purchase_order_items_purchase_order_id_index', 'ON purchase_order_items (purchase_order_id)');
        ConcurrentIndex::ensure('purchase_order_items_product_variant_id_index', 'ON purchase_order_items (product_variant_id)');
        ConcurrentIndex::ensure('supplier_payments_supplier_invoice_id_index', 'ON supplier_payments (supplier_invoice_id)');
        ConcurrentIndex::ensure('supplier_invoices_purchase_order_id_index', 'ON supplier_invoices (purchase_order_id)');
        ConcurrentIndex::ensure('supplier_deliveries_purchase_order_id_index', 'ON supplier_deliveries (purchase_order_id)');
        ConcurrentIndex::ensure('supplier_delivery_items_purchase_order_item_id_index', 'ON supplier_delivery_items (purchase_order_item_id)');
        ConcurrentIndex::ensure('operation_idempotency_keys_created_at_index', 'ON operation_idempotency_keys (created_at)');
        ConcurrentIndex::ensure('supplier_payments_voided_at_index', 'ON supplier_payments (supplier_id, voided_at)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_payments_voided_at_index');
        ConcurrentIndex::drop('operation_idempotency_keys_created_at_index');
        ConcurrentIndex::drop('supplier_delivery_items_purchase_order_item_id_index');
        ConcurrentIndex::drop('supplier_deliveries_purchase_order_id_index');
        ConcurrentIndex::drop('supplier_invoices_purchase_order_id_index');
        ConcurrentIndex::drop('supplier_payments_supplier_invoice_id_index');
        ConcurrentIndex::drop('purchase_order_items_product_variant_id_index');
        ConcurrentIndex::drop('purchase_order_items_purchase_order_id_index');
    }
};
