<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->setPrecision(14);
    }

    public function down(): void
    {
        $this->setPrecision(12);
    }

    private function setPrecision(int $precision): void
    {
        foreach ([
            'customers' => ['wallet_balance' => 2],
            'product_variants' => ['last_cost_price' => 4],
            'orders' => ['subtotal' => 2, 'discount_amount' => 2, 'shipping_cost' => 2, 'tax_amount' => 2, 'grand_total' => 2, 'cod_fee' => 2],
            'order_items' => ['discount_amount' => 2, 'tax_amount' => 2, 'line_total' => 2],
            'return_request_items' => ['unit_refund_price' => 4],
        ] as $table => $columns) {
            DB::transaction(function () use ($table, $columns, $precision): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                $alterations = [];
                foreach ($columns as $column => $scale) {
                    $alterations[] = "ALTER COLUMN {$column} TYPE numeric({$precision}, {$scale})";
                }
                DB::statement("ALTER TABLE {$table} ".implode(', ', $alterations));
            });
        }
    }
};
