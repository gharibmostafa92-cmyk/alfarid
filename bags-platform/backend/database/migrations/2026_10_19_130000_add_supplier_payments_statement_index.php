<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds composite index on (supplier_id, payment_date) CONCURRENTLY outside a transaction
 * to accelerate supplier statement balance calculations and date-range queries.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('supplier_payments_supplier_date_index', 'ON supplier_payments (supplier_id, payment_date)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_payments_supplier_date_index');
    }
};
