<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens cost_price to numeric(14,4) to match supplier receipt unit cost rounding, so
     * Postgres silently rounded every weighted-average write. Widen to (14,4).
     * Run in a maintenance window: rewriting the column takes ACCESS EXCLUSIVE.
     */
    public function up(): void
    {
        // F14: only widen when still narrow; re-running must not take ACCESS EXCLUSIVE for nothing.
        $type = DB::selectOne("SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_name = 'product_variants' AND column_name = 'cost_price'");
        if ($type === null || (int) $type->numeric_precision !== 14 || (int) $type->numeric_scale !== 4) {
            DB::statement('ALTER TABLE product_variants ALTER COLUMN cost_price TYPE numeric(14,4)');
        }
    }

    public function down(): void
    {
        // F14: narrowing truncates 4-decimal costs; keep 14,4 (reversal is a no-op by design).
    }
};
