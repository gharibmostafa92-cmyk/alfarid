<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * H4: cost_price was numeric(12,2) while receipts round to 4 decimals, so
     * Postgres silently rounded every weighted-average write. Widen to (14,4).
     * Run in a maintenance window: rewriting the column takes ACCESS EXCLUSIVE.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE product_variants ALTER COLUMN cost_price TYPE numeric(14,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants ALTER COLUMN cost_price TYPE numeric(12,2)');
    }
};
