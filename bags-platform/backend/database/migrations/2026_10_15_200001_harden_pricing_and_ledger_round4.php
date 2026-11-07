<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardening round 4:
 * - Restrict price list currency to EGP via CHECK constraint until multi-currency support is introduced.
 * - Composite ledger indexes matching query access patterns (branch/variant + id desc) and foreign key indexes.
 * - Database check constraints on quantity transitions: 0 <= received <= shipped <= approved <= requested,
 *   and unique item line constraints per transfer and stocktake.
 * - Seeds default movement, transfer, and stocktake numbering settings using insertOrIgnore.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Supported currency constraint (EGP only at present)
        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_currency_supported_check CHECK (currency = 'EGP')");

        // Composite stock ledger indexes
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_branch_id_id_index ON stock_movements (branch_id, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_variant_id_id_index ON stock_movements (product_variant_id, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_location_id_index ON stock_movements (location_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_created_by_index ON stock_movements (created_by)');

        // Stock transfer item check constraints
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_requested_positive_check CHECK (requested_qty > 0)');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_approved_range_check CHECK (approved_qty >= 0 AND approved_qty <= requested_qty)');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_shipped_range_check CHECK (shipped_qty >= 0 AND shipped_qty <= approved_qty)');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_received_range_check CHECK (received_qty >= 0 AND received_qty <= shipped_qty)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_items_unique_line ON stock_transfer_items (stock_transfer_id, product_variant_id, from_location_id, to_location_id) NULLS NOT DISTINCT');

        // Stocktake item check constraints and item deduplication
        DB::statement('ALTER TABLE stocktake_items ADD CONSTRAINT stocktake_items_system_nonnegative_check CHECK (system_qty >= 0)');
        DB::statement('ALTER TABLE stocktake_items ADD CONSTRAINT stocktake_items_counted_nonnegative_check CHECK (counted_qty IS NULL OR counted_qty >= 0)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS stocktake_items_unique_variant ON stocktake_items (stocktake_id, product_variant_id)');

        // Default document numbering sequence initializers
        $now = now();
        $rows = [];
        foreach ([
            ['movement_prefix', 'MOV-', 'string'], ['movement_next_number', '1000', 'integer'], ['movement_padding', '6', 'integer'],
            ['transfer_prefix', 'TRF-', 'string'], ['transfer_next_number', '1000', 'integer'], ['transfer_padding', '6', 'integer'],
            ['stocktake_prefix', 'STK-', 'string'], ['stocktake_next_number', '1000', 'integer'], ['stocktake_padding', '6', 'integer'],
        ] as [$key, $value, $type]) {
            $rows[] = ['group' => 'numbering', 'key' => $key, 'value' => $value, 'type' => $type, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('settings')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE price_lists DROP CONSTRAINT IF EXISTS price_lists_currency_supported_check');

        DB::statement('DROP INDEX IF EXISTS stock_movements_branch_id_id_index');
        DB::statement('DROP INDEX IF EXISTS stock_movements_variant_id_id_index');
        DB::statement('DROP INDEX IF EXISTS stock_movements_location_id_index');
        DB::statement('DROP INDEX IF EXISTS stock_movements_created_by_index');

        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_requested_positive_check');
        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_approved_range_check');
        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_shipped_range_check');
        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_received_range_check');
        DB::statement('DROP INDEX IF EXISTS stock_transfer_items_unique_line');

        DB::statement('ALTER TABLE stocktake_items DROP CONSTRAINT IF EXISTS stocktake_items_system_nonnegative_check');
        DB::statement('ALTER TABLE stocktake_items DROP CONSTRAINT IF EXISTS stocktake_items_counted_nonnegative_check');
        DB::statement('DROP INDEX IF EXISTS stocktake_items_unique_variant');
    }
};
