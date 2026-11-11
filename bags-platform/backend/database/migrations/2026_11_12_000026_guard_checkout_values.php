<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const CONSTRAINTS = [
        ['orders', 'orders_nonnegative_amounts', 'CHECK (subtotal >= 0 AND discount_amount >= 0 AND grand_total >= 0 AND shipping_cost >= 0 AND tax_amount >= 0 AND cod_fee >= 0)'],
        ['order_items', 'order_items_positive_qty', 'CHECK (qty > 0)'],
        ['cart_items', 'cart_items_positive_qty', 'CHECK (qty > 0)'],
        ['coupons', 'coupons_value_and_limit_bounds', "CHECK (value >= 0 AND (type <> 'percentage' OR value <= 100) AND used_count >= 0 AND usage_limit >= 0 AND usage_limit_per_customer >= 0)"],
        ['orders', 'orders_coupon_id_foreign', 'FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE RESTRICT'],
        ['carts', 'carts_coupon_id_foreign', 'FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL'],
    ];

    public function up(): void
    {
        foreach (self::CONSTRAINTS as [$table, $name, $definition]) {
            DB::transaction(function () use ($table, $name, $definition): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$name, $table]) === null) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition} NOT VALID");
                }
            });

            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    public function down(): void
    {
        foreach (self::CONSTRAINTS as [$table, $name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
