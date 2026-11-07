<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates foreign key and statement lookup indexes CONCURRENTLY outside a transaction
 * to prevent ACCESS EXCLUSIVE table locks during production migrations.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS purchase_order_items_purchase_order_id_index ON purchase_order_items (purchase_order_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS purchase_order_items_product_variant_id_index ON purchase_order_items (product_variant_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_payments_supplier_invoice_id_index ON supplier_payments (supplier_invoice_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_invoices_purchase_order_id_index ON supplier_invoices (purchase_order_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_deliveries_purchase_order_id_index ON supplier_deliveries (purchase_order_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_delivery_items_purchase_order_item_id_index ON supplier_delivery_items (purchase_order_item_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS operation_idempotency_keys_created_at_index ON operation_idempotency_keys (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_payments_voided_at_index ON supplier_payments (supplier_id, voided_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_payments_voided_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS operation_idempotency_keys_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_delivery_items_purchase_order_item_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_deliveries_purchase_order_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_invoices_purchase_order_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_payments_supplier_invoice_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS purchase_order_items_product_variant_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS purchase_order_items_purchase_order_id_index');
    }
};
