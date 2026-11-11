<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Composite stock movement ledger indexes to accelerate branch stock reconciliations and audits:
     * - Stale snapshot checks during stocktake approvals: MAX(id) GROUP BY variant WHERE branch/location/bucket.
     * - Inventory reconciliation window queries: PARTITION BY branch/location/variant/bucket ORDER BY id.
     * The branch/id cursor index already exists in the round4 migration.
     */
    public function up(): void
    {
        ConcurrentIndex::ensure('stock_movements_stale_check_index', 'ON stock_movements (branch_id, location_id, affected_bucket, product_variant_id, id)');

        ConcurrentIndex::ensure('stock_movements_reconcile_index', 'ON stock_movements (branch_id, location_id, product_variant_id, affected_bucket, id)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_reconcile_index');
        ConcurrentIndex::drop('stock_movements_stale_check_index');
    }
};
