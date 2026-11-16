<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE supplier_payments ALTER COLUMN amount TYPE numeric(20,2)');
            DB::statement('ALTER TABLE purchase_order_items ALTER COLUMN line_total TYPE numeric(20,4)');
            DB::statement('ALTER TABLE purchase_orders ALTER COLUMN subtotal TYPE numeric(20,2), ALTER COLUMN discount_amount TYPE numeric(20,2), ALTER COLUMN tax_amount TYPE numeric(20,2), ALTER COLUMN grand_total TYPE numeric(20,2)');
            DB::statement('ALTER TABLE suppliers ALTER COLUMN opening_balance TYPE numeric(20,2), ALTER COLUMN current_balance TYPE numeric(20,2)');
            DB::statement('ALTER TABLE supplier_invoices ALTER COLUMN subtotal TYPE numeric(20,2), ALTER COLUMN tax_amount TYPE numeric(20,2), ALTER COLUMN total_amount TYPE numeric(20,2), ALTER COLUMN paid_amount TYPE numeric(20,2)');
        });
    }

    public function down(): void
    {
        // Preserve the widened precision to avoid truncating valid ledger data on rollback.
    }
};
