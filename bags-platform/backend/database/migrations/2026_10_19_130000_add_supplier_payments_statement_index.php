<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فهرس كشف حساب المورد (L6): استعلامات الرصيد الافتتاحي وحركات الفترة في
 * SupplierFinanceService::statement تفرز بـ (supplier_id, payment_date)،
 * فتُنشأ خارج المعاملة وبـ CONCURRENTLY كبقية فهارس 120002.
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
