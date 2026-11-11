<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Models\Branch;
use App\Models\InventoryReconcileRun;
use App\Services\Finance\StatementBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Periodic inventory audit verifying ledger continuity and synchronization with current stock levels.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--branch_id= : Restrict check to a single branch} {--all-branches : Scan all active branches sequentially} {--incremental : Reconcile recently modified inventory keys only} {--limit=1000 : Maximum keys per incremental batch}';

    protected $description = 'Reconcile stock level balances against movement ledger history and flag discrepancies';

    /**
     * Execute the inventory reconciliation command.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $branch = $this->option('branch_id');
        if ($branch !== null && filter_var($branch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('branch_id يجب أن يكون عددًا صحيحًا موجبًا.');

            return self::INVALID;
        }
        if ($branch !== null && ! Branch::query()->whereKey((int) $branch)->exists()) {
            $this->error('الفرع غير موجود.');

            return self::INVALID;
        }

        // Running branch-by-branch avoids locking large ledger sections and reduces statement duration.
        if ((bool) $this->option('all-branches')) {
            if ($branch !== null) {
                $this->error('مرر branch_id أو all-branches وليس الاثنين معًا.');

                return self::INVALID;
            }
            $failures = 0;
            foreach (Branch::query()->where('is_active', true)->orderBy('id')->pluck('id') as $branchId) {
                $this->info("فحص الفرع {$branchId}...");
                if (StatementBudget::run(300000, fn (): int => $this->reconcile((int) $branchId)) !== self::SUCCESS) {
                    $failures++;
                }
            }
            if ($failures > 0) {
                $this->error("فشلت المطابقة في {$failures} من الفروع.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        return StatementBudget::run(300000, fn (): int => $this->reconcile($branch === null ? null : (int) $branch));
    }

    /**
     * Executes the reconciliation SQL CTE for a specific branch or all branches.
     *
     * @param  int|null  $branch  Target branch ID, or null to reconcile across all branches
     * @return int Command exit code (SUCCESS, FAILURE, or INVALID)
     */
    private function reconcile(?int $branch): int
    {
        $incremental = (bool) $this->option('incremental');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('limit يجب أن يكون بين 1 و10000.');

            return self::INVALID;
        }
        // One SQL statement sees one committed snapshot, even while sales continue.
        // Window functions also catch broken chains and movements without a stock row.
        $sql = <<<'SQL'
WITH levels AS (
    SELECT * FROM stock_levels WHERE (?::bigint IS NULL OR branch_id = ?::bigint)
), ledger AS (
    SELECT *,
        LAG(balance_after, 1, 0::numeric) OVER (
            PARTITION BY branch_id, location_id, product_variant_id, affected_bucket ORDER BY id
        ) AS previous_balance,
        ROW_NUMBER() OVER (
            PARTITION BY branch_id, location_id, product_variant_id, affected_bucket ORDER BY id DESC
        ) AS recency
    FROM stock_movements WHERE (?::bigint IS NULL OR branch_id = ?::bigint)
), buckets AS (
    SELECT l.id, l.branch_id, l.location_id, l.product_variant_id, b.bucket, b.balance
    FROM levels l CROSS JOIN LATERAL (VALUES
        ('on_hand', l.on_hand), ('reserved', l.reserved),
        ('damaged', l.damaged), ('in_transit', l.in_transit)
    ) b(bucket, balance)
), problems AS (
    SELECT 'chain' AS issue, id, branch_id, location_id, product_variant_id, affected_bucket AS bucket,
        previous_balance AS expected, balance_before AS actual
    FROM ledger WHERE balance_before <> previous_balance
    UNION ALL
    SELECT 'balance', b.id, b.branch_id, b.location_id, b.product_variant_id, b.bucket,
        COALESCE(m.balance_after, 0), b.balance
    FROM buckets b LEFT JOIN ledger m ON m.recency = 1
        AND m.branch_id = b.branch_id AND m.product_variant_id = b.product_variant_id
        AND COALESCE(m.location_id, 0) = COALESCE(b.location_id, 0) AND m.affected_bucket = b.bucket
    WHERE b.balance <> COALESCE(m.balance_after, 0)
    UNION ALL
    SELECT 'missing_level', m.id, m.branch_id, m.location_id, m.product_variant_id,
        m.affected_bucket, m.balance_after, NULL::numeric
    FROM ledger m LEFT JOIN levels l ON l.branch_id = m.branch_id
        AND l.product_variant_id = m.product_variant_id AND COALESCE(l.location_id, 0) = COALESCE(m.location_id, 0)
    WHERE m.recency = 1 AND l.id IS NULL
)
SELECT (SELECT COUNT(*) FROM levels) AS checked, (SELECT COUNT(*) FROM problems) AS problem_count,
    COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY branch_id, product_variant_id, id LIMIT 100) p), '[]'::json) AS samples
SQL;
        if ($incremental) {
            // Revision comparison survives concurrent commits. A simple MAX(id) cursor
            // would skip a lower sequence ID committed after the reconciliation snapshot.
            $matches = fn (string $alias): string => "EXISTS (SELECT 1 FROM chosen c WHERE c.branch_id = {$alias}.branch_id AND c.product_variant_id = {$alias}.product_variant_id AND COALESCE(c.location_id, 0) = COALESCE({$alias}.location_id, 0))";
            // Prefer least-recently-checked keys so permanently broken records do not starve the remaining backlog.
            $sql = str_replace('WITH levels AS (', "WITH chosen AS MATERIALIZED (SELECT * FROM inventory_reconcile_work WHERE (?::bigint IS NULL OR branch_id = ?::bigint) ORDER BY checked_at NULLS FIRST, key LIMIT {$limit}), levels AS (", $sql);
            $sql = str_replace('SELECT * FROM stock_levels WHERE', 'SELECT * FROM stock_levels WHERE '.$matches('stock_levels').' AND', $sql);
            $sql = str_replace('FROM stock_movements WHERE', 'FROM stock_movements WHERE '.$matches('stock_movements').' AND', $sql);
            $sql = str_replace("\nSELECT (SELECT COUNT(*) FROM levels)", <<<'SQL'
, locked AS MATERIALIZED (
    SELECT w.key, EXISTS (SELECT 1 FROM problems p WHERE p.branch_id = c.branch_id
        AND p.product_variant_id = c.product_variant_id AND COALESCE(p.location_id, 0) = COALESCE(c.location_id, 0)) AS has_problem
    FROM inventory_reconcile_work w JOIN chosen c USING (key)
    WHERE w.revision = c.revision
    ORDER BY w.key FOR UPDATE OF w SKIP LOCKED
), cleaned AS (
    DELETE FROM inventory_reconcile_work w USING locked l
    WHERE w.key = l.key AND NOT l.has_problem
    RETURNING w.key
), checked AS (
    UPDATE inventory_reconcile_work w SET checked_at = CURRENT_TIMESTAMP FROM locked l
    WHERE w.key = l.key AND l.has_problem
    RETURNING w.key
)
SELECT (SELECT COUNT(*) FROM levels)
SQL, $sql);
        }
        $bindings = array_fill(0, $incremental ? 6 : 4, $branch);
        $result = DB::selectOne($sql, $bindings, false);

        // Persist latest run outcome for the inventory_health assistant tool to avoid expensive real-time audits.
        InventoryReconcileRun::query()->create([
            'branch_id' => $branch !== null ? (int) $branch : null,
            'checked' => (int) $result->checked,
            'problem_count' => (int) $result->problem_count,
            'samples' => json_decode((string) $result->samples, true) ?? [],
        ]);

        // Retain two weeks of reconciliation run logs to keep historical trends while capping storage growth.
        InventoryReconcileRun::query()->where('created_at', '<', now()->subDays(14))->delete();

        if ((int) $result->problem_count > 0) {
            $samples = json_decode($result->samples, true, flags: JSON_THROW_ON_ERROR);
            foreach ($samples as $problem) {
                $this->error(json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            Log::error('Inventory reconciliation failed', ['problem_count' => (int) $result->problem_count, 'samples' => $samples]);
            $this->error("المطابقة فشلت: {$result->problem_count} فرق في {$result->checked} صف رصيد.");

            return self::FAILURE;
        }

        $this->info("المطابقة سليمة: {$result->checked} صف رصيد مطابق للدفتر.");

        return self::SUCCESS;
    }
}
