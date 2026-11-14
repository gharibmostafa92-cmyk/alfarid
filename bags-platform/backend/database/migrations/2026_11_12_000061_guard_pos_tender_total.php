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

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if (DB::scalar("SELECT 1 FROM pg_constraint WHERE conname = 'orders_pos_tender_total' AND conrelid = 'orders'::regclass") === null) {
                DB::statement(<<<'SQL'
                    ALTER TABLE orders ADD CONSTRAINT orders_pos_tender_total CHECK (
                        (cash_amount IS NULL AND card_amount IS NULL)
                        OR (
                            COALESCE(cash_amount, 0) >= 0 AND COALESCE(card_amount, 0) >= 0
                            AND COALESCE(cash_amount, 0) + COALESCE(card_amount, 0) = grand_total
                        )
                    ) NOT VALID
                    SQL);
            }
        });

        DB::statement('ALTER TABLE orders VALIDATE CONSTRAINT orders_pos_tender_total');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_pos_tender_total');
        });
    }
};
