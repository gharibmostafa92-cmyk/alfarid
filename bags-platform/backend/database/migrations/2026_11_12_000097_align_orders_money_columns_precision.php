<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen orders and order_items monetary columns to numeric(14, 2) to align
 * with exchange_credit, pos_shifts, and treasuries precision.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = [
        'orders' => [
            'subtotal',
            'discount_amount',
            'shipping_cost',
            'tax_amount',
            'grand_total',
            'cash_amount',
            'card_amount',
        ],
        'order_items' => [
            'unit_price',
            'discount_amount',
            'line_total',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            DB::statement('DROP TRIGGER IF EXISTS wholesale_order_check_totals ON orders');

            foreach (self::COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(14, 2)");
                }
            }

            DB::unprepared(<<<'SQL'
                CREATE CONSTRAINT TRIGGER wholesale_order_check_totals
                    AFTER UPDATE OF grand_total, status ON orders
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                    WHEN (OLD.grand_total IS DISTINCT FROM NEW.grand_total OR
                        (OLD.status = 'cancelled' AND NEW.status <> 'cancelled'))
                    EXECUTE FUNCTION check_wholesale_allocation_totals();
            SQL);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            DB::statement('DROP TRIGGER IF EXISTS wholesale_order_check_totals ON orders');

            foreach (self::COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(12, 2)");
                }
            }

            DB::unprepared(<<<'SQL'
                CREATE CONSTRAINT TRIGGER wholesale_order_check_totals
                    AFTER UPDATE OF grand_total, status ON orders
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                    WHEN (OLD.grand_total IS DISTINCT FROM NEW.grand_total OR
                        (OLD.status = 'cancelled' AND NEW.status <> 'cancelled'))
                    EXECUTE FUNCTION check_wholesale_allocation_totals();
            SQL);
        });
    }
};
