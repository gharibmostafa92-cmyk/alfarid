<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHECKS = [
        'stock_transfers' => [
            'stock_transfers_valid_status' => "status IN ('draft', 'approved', 'shipped', 'received', 'cancelled')",
            'stock_transfers_distinct_branches' => 'from_branch_id <> to_branch_id',
        ],
        'stocktakes' => [
            'stocktakes_valid_status' => "status IN ('draft', 'in_progress', 'approved', 'cancelled')",
        ],
        'price_lists' => [
            'price_lists_valid_type' => "type IN ('retail', 'wholesale', 'distributor', 'customer_group', 'customer_specific')",
            'price_lists_valid_dates' => 'starts_at IS NULL OR ends_at IS NULL OR starts_at <= ends_at',
        ],
        'price_list_items' => [
            'price_list_items_valid_amounts' => 'min_qty > 0 AND price >= 0',
            'price_list_items_valid_dates' => 'starts_at IS NULL OR ends_at IS NULL OR starts_at <= ends_at',
        ],
        'customer_groups' => [
            'customer_groups_valid_discount' => 'discount_percentage >= 0 AND discount_percentage <= 100',
        ],
    ];

    public function up(): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
            }
        }
    }
};
