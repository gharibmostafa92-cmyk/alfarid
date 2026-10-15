<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the catalog's existing price authoritative, without a second editable value.
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('base_price', 12, 4)->storedAs('COALESCE(retail_price, 0)');
        });

        foreach (['reference_number', 'product_variant_id', 'branch_id', 'type', 'affected_bucket', 'created_by'] as $column) {
            DB::statement("ALTER TABLE stock_movements ALTER COLUMN {$column} SET NOT NULL");
        }

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ('purchase_receipt', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'damage', 'transfer_out', 'transfer_in', 'stocktake_gain', 'stocktake_loss', 'reserve', 'release_reserve'))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_bucket CHECK (affected_bucket IN ('on_hand', 'reserved', 'damaged', 'in_transit'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_balance CHECK (quantity <> 0 AND balance_before >= 0 AND balance_after >= 0 AND balance_after = balance_before + quantity)');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_valid_balances CHECK (on_hand >= 0 AND reserved >= 0 AND damaged >= 0 AND in_transit >= 0 AND reorder_level >= 0 AND on_hand >= reserved + damaged)');
        DB::statement("CREATE OR REPLACE FUNCTION prevent_stock_movement_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Stock movements are immutable'; RETURN NULL; END; $$");
        DB::statement('CREATE TRIGGER stock_movements_immutable BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION prevent_stock_movement_mutation()');
        DB::statement('CREATE TRIGGER stock_movements_no_truncate BEFORE TRUNCATE ON stock_movements FOR EACH STATEMENT EXECUTE FUNCTION prevent_stock_movement_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS stock_movements_no_truncate ON stock_movements');
        DB::statement('DROP TRIGGER IF EXISTS stock_movements_immutable ON stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS prevent_stock_movement_mutation()');
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT stock_levels_valid_balances');
        foreach (['stock_movements_valid_type', 'stock_movements_valid_bucket', 'stock_movements_valid_balance'] as $constraint) {
            DB::statement("ALTER TABLE stock_movements DROP CONSTRAINT {$constraint}");
        }
        foreach (['reference_number', 'product_variant_id', 'branch_id', 'type', 'affected_bucket', 'created_by'] as $column) {
            DB::statement("ALTER TABLE stock_movements ALTER COLUMN {$column} DROP NOT NULL");
        }
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('base_price'));
    }
};
