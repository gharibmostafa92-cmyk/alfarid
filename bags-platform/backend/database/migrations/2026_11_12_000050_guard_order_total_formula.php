<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $invalid = DB::selectOne(<<<'SQL'
            SELECT id FROM orders
            WHERE subtotal::text = 'NaN' OR discount_amount::text = 'NaN' OR shipping_cost::text = 'NaN'
                OR tax_amount::text = 'NaN' OR cod_fee::text = 'NaN' OR grand_total::text = 'NaN'
                OR grand_total IS DISTINCT FROM ((subtotal - discount_amount) + shipping_cost + tax_amount + cod_fee)
            LIMIT 1
            SQL);
        if ($invalid !== null) {
            throw new \RuntimeException('Reconcile order '.$invalid->id.' before enabling the order total formula.');
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'orders_total_formula' AND conrelid = 'orders'::regclass") === null) {
                DB::statement(<<<'SQL'
                    ALTER TABLE orders ADD CONSTRAINT orders_total_formula CHECK (
                        subtotal::text <> 'NaN' AND discount_amount::text <> 'NaN' AND shipping_cost::text <> 'NaN'
                        AND tax_amount::text <> 'NaN' AND cod_fee::text <> 'NaN' AND grand_total::text <> 'NaN'
                        AND grand_total = (subtotal - discount_amount) + shipping_cost + tax_amount + cod_fee
                    ) NOT VALID
                    SQL);
            }
        });

        DB::statement('ALTER TABLE orders VALIDATE CONSTRAINT orders_total_formula');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_formula');
    }
};
