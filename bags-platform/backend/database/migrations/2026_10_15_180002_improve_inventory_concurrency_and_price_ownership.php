<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_TYPES = "'purchase_receipt', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'damage', 'transfer_out', 'transfer_in', 'stocktake_gain', 'stocktake_loss', 'reserve', 'release_reserve'";

    public function up(): void
    {
        DB::statement('CREATE SEQUENCE stock_movement_number_seq OWNED BY stock_movements.id');
        DB::statement("SELECT setval('stock_movement_number_seq', GREATEST(
            COALESCE((SELECT value::bigint FROM settings WHERE key = 'movement_next_number'), 1000),
            COALESCE((SELECT MAX((substring(reference_number from '[0-9]+$'))::bigint) + 1 FROM stock_movements), 1)
        ), false)");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_valid_type');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ('.self::ORIGINAL_TYPES.", 'damage_release', 'damage_write_off', 'transfer_in_transit', 'transfer_transit_release', 'transfer_shortage'))");

        Schema::table('price_lists', function (Blueprint $table): void {
            foreach (['customer_id', 'customer_group_id', 'branch_id'] as $column) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on(match ($column) {
                    'customer_id' => 'customers', 'customer_group_id' => 'customer_groups', 'branch_id' => 'branches',
                })->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_valid_owner CHECK (
            (type = 'customer_specific' AND customer_id IS NOT NULL AND customer_group_id IS NULL)
            OR (type = 'customer_group' AND customer_group_id IS NOT NULL AND customer_id IS NULL)
            OR (type IN ('retail', 'wholesale', 'distributor') AND customer_id IS NULL AND customer_group_id IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement("UPDATE settings SET value = GREATEST(value::bigint,
            (SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM stock_movement_number_seq))::text
            WHERE key = 'movement_next_number'");
        DB::statement('ALTER TABLE price_lists DROP CONSTRAINT price_lists_valid_owner');
        Schema::table('price_lists', function (Blueprint $table): void {
            foreach (['customer_id', 'customer_group_id', 'branch_id'] as $column) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on(match ($column) {
                    'customer_id' => 'customers', 'customer_group_id' => 'customer_groups', 'branch_id' => 'branches',
                })->nullOnDelete();
            }
        });
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_valid_type');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ('.self::ORIGINAL_TYPES.'))');
        DB::statement('DROP SEQUENCE stock_movement_number_seq');
    }
};
