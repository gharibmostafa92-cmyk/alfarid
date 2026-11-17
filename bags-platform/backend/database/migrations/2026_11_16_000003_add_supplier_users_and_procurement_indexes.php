<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Task 2:
 * MEDIUM-06: functional unique index on supplier_users(lower(btrim(email)))
 * MEDIUM-08: composite indexes on (supplier_id, id DESC) for purchase_orders and supplier_deliveries
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure(
            'supplier_users_lower_email_idx',
            'ON supplier_users (lower(btrim(email)))',
            true,
        );

        ConcurrentIndex::ensure(
            'purchase_orders_supplier_id_id_idx',
            'ON purchase_orders (supplier_id, id DESC)',
        );

        ConcurrentIndex::ensure(
            'supplier_deliveries_supplier_id_id_idx',
            'ON supplier_deliveries (supplier_id, id DESC)',
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_deliveries_supplier_id_id_idx');
        ConcurrentIndex::drop('purchase_orders_supplier_id_id_idx');
        ConcurrentIndex::drop('supplier_users_lower_email_idx');
    }
};
