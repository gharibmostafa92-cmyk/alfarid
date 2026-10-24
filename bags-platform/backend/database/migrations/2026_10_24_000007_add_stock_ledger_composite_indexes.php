<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * M7: فهارس الدفتر المركبة — الأعمدة المنفردة لا تكفي مع النمو:
     * - فحص اللقطة القديمة في اعتماد الجرد: MAX(id) GROUP BY variant
     *   WHERE branch/location/bucket.
     * - ReconcileInventory: PARTITION BY branch/location/variant/bucket ORDER BY id.
     * The branch/id cursor index already exists in the round4 migration.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stock_movements_stale_check_index
            ON stock_movements (branch_id, location_id, affected_bucket, product_variant_id, id)');

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stock_movements_reconcile_index
            ON stock_movements (branch_id, location_id, product_variant_id, affected_bucket, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_reconcile_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_stale_check_index');
    }
};
