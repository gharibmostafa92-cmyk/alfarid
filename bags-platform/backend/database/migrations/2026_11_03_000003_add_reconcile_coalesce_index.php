<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Incremental reconcile probes (branch_id, product_variant_id,
     * COALESCE(location_id, 0)) in EXISTS subqueries and ledger joins.
     * IS NOT DISTINCT FROM is not index-sargable and blocks hash joins;
     * plain COALESCE equality matches this expression index instead.
     * location_id 0 never collides with a real location (ids start at 1).
     */
    public function up(): void
    {
        ConcurrentIndex::ensure('stock_movements_reconcile_coalesce_index', 'ON stock_movements (branch_id, product_variant_id, (COALESCE(location_id, 0)), affected_bucket, id)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_reconcile_coalesce_index');
    }
};
