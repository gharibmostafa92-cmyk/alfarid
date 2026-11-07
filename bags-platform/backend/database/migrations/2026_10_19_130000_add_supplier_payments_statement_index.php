<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds composite index on (supplier_id, payment_date) CONCURRENTLY outside a transaction
 * to accelerate supplier statement balance calculations and date-range queries.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS supplier_payments_supplier_date_index ON supplier_payments (supplier_id, payment_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_payments_supplier_date_index');
    }
};
