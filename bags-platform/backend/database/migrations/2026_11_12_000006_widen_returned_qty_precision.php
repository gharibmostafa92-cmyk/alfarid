<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F21: order_items.returned_qty (10,4) cannot hold a full return of qty (12,4) above 999,999.
     */
    public function up(): void
    {
        $type = DB::selectOne("SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_name = 'order_items' AND column_name = 'returned_qty'");
        if ($type === null || (int) $type->numeric_precision !== 12 || (int) $type->numeric_scale !== 4) {
            DB::statement('ALTER TABLE order_items ALTER COLUMN returned_qty TYPE numeric(12,4)');
        }
    }

    public function down(): void
    {
        // Narrowing would truncate large returns; no-op by design.
    }
};
