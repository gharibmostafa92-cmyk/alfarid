<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Explicit opt-in benchmark: two million ledger entries, never part of the default suite. */
class InventoryLargeLedgerPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deep_cursor_and_branch_reconciliation_with_two_million_movements(): void
    {
        $this->assertSame('alfarid_inventory_review_20261105', DB::connection()->getDatabaseName(), 'Use the isolated inventory review database for this benchmark.');
        $actor = User::factory()->create();
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 2000000, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $started = microtime(true);
        // Historical fixture loading must not update the same work-queue row
        // two million times inside one transaction. All ledger constraints stay
        // enabled, and the queue trigger is restored before measurements.
        DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_reconciliation_work');
        try {
            for ($first = 1; $first <= 2000000; $first += 100000) {
                DB::statement(<<<'SQL'
INSERT INTO stock_movements (reference_number, product_variant_id, branch_id, location_id,
    type, affected_bucket, quantity, balance_before, balance_after, created_by, created_at)
SELECT 'PERF-' || n, ?, ?, NULL, 'adjustment_in', 'on_hand', 1, n - 1, n, ?,
    CURRENT_TIMESTAMP
FROM generate_series(?::integer, ?::integer) AS n
SQL, [$variant->id, $branch->id, $actor->id, $first, $first + 99999]);
            }
        } finally {
            DB::statement('ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_reconciliation_work');
        }
        $setupMs = (microtime(true) - $started) * 1000;
        $cursor = (int) DB::table('stock_movements')->where('branch_id', $branch->id)->min('id') + 100;
        $started = microtime(true);
        $rows = DB::table('stock_movements')->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)->where('id', '<', $cursor)->orderByDesc('id')->limit(50)->get();
        $cursorMs = (microtime(true) - $started) * 1000;
        $started = microtime(true);
        $this->artisan('inventory:reconcile', ['--branch_id' => $branch->id])->assertExitCode(0);
        $reconcileMs = (microtime(true) - $started) * 1000;
        file_put_contents(storage_path('logs/inventory-large-ledger-acceptance.json'), json_encode([
            'movements' => 2000000, 'setup_ms' => round($setupMs, 2),
            'deep_cursor_ms' => round($cursorMs, 2), 'branch_reconcile_ms' => round($reconcileMs, 2),
            'scope' => 'synthetic single-branch ledger; excludes production contention and HTTP',
        ], JSON_PRETTY_PRINT));
        $this->assertCount(50, $rows);
        $this->assertLessThan(5000, $cursorMs);
        $this->assertLessThan(300000, $reconcileMs);
    }
}
