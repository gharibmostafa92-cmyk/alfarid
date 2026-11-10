<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F20: stocktake_items(stocktake_id) is a leftmost prefix of the unique
     * (stocktake_id, product_variant_id) constraint; same for stock_transfer_items.
     * Every INSERT was maintaining a redundant index.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stocktake_items_stocktake_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_transfer_items_stock_transfer_id_index');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stocktake_items_stocktake_id_index ON stocktake_items (stocktake_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stock_transfer_items_stock_transfer_id_index ON stock_transfer_items (stock_transfer_id)');
    }
};
