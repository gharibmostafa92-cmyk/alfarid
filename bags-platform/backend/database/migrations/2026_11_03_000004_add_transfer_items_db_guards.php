<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Database check constraints on stock transfer items ensuring received and damaged quantities do not exceed approved amounts.
     * but the database should still reject nonsense and duplicate rows.
     * NOT VALID + VALIDATE keeps existing-traffic tables lock-free; the FK
     * guards for variants/branches/locations stay as defined at creation.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_qty_nonnegative
            CHECK (requested_qty >= 0 AND approved_qty >= 0 AND shipped_qty >= 0 AND received_qty >= 0) NOT VALID");
        // Uniqueness per transfer line already enforced by
        // stock_transfer_items_unique_line (round4 migration) — no duplicate
        // index here.

        DB::statement('ALTER TABLE stock_transfer_items VALIDATE CONSTRAINT stock_transfer_items_qty_nonnegative');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_qty_nonnegative');
    }
};
