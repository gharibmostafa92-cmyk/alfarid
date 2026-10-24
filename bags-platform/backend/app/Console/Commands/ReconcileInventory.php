<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مطابقة دورية بين الدفتر والأرصدة (M-8): القاعدة تضمن صحة كل حركة على حدة،
 * والكتابة المباشرة التي تتجاوز حماية الموديل تحتاج مطابقة مستقلة.
 * الفحص يتحقق لكل وعاء من أمرين:
 * 1. رصيد stock_levels يساوي آخر balance_after في الدفتر لنفس الوعاء.
 * 2. تسلسل الدفتر متصل: balance_before لكل حركة يساوي balance_after لسابقتها.
 * يُشغَّل دوريًا (جدولة ليلية) ويُرجع كود فشل عند وجود أي فرق للتنبيه.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--branch_id= : قصر الفحص على فرع واحد} {--incremental : فحص المفاتيح المتغيرة فقط} {--limit=1000 : أقصى عدد مفاتيح في الدفعة التراكمية}';

    protected $description = 'مطابقة أرصدة المخزون مع دفتر الحركات وكشف أي كتابة مباشرة';

    public function handle(): int
    {
        $branch = $this->option('branch_id');
        if ($branch !== null && filter_var($branch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('branch_id يجب أن يكون عددًا صحيحًا موجبًا.');

            return self::INVALID;
        }

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
        AND m.location_id IS NOT DISTINCT FROM b.location_id AND m.affected_bucket = b.bucket
    WHERE b.balance <> COALESCE(m.balance_after, 0)
    UNION ALL
    SELECT 'missing_level', m.id, m.branch_id, m.location_id, m.product_variant_id,
        m.affected_bucket, m.balance_after, NULL::numeric
    FROM ledger m LEFT JOIN levels l ON l.branch_id = m.branch_id
        AND l.product_variant_id = m.product_variant_id AND l.location_id IS NOT DISTINCT FROM m.location_id
    WHERE m.recency = 1 AND l.id IS NULL
)
SELECT (SELECT COUNT(*) FROM levels) AS checked, (SELECT COUNT(*) FROM problems) AS problem_count,
    COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY branch_id, product_variant_id, id LIMIT 100) p), '[]'::json) AS samples
SQL;
        if ($incremental) {
            // Revision comparison survives concurrent commits. A simple MAX(id) cursor
            // would skip a lower sequence ID committed after the reconciliation snapshot.
            $matches = fn (string $alias): string => "EXISTS (SELECT 1 FROM chosen c WHERE c.branch_id = {$alias}.branch_id AND c.product_variant_id = {$alias}.product_variant_id AND c.location_id IS NOT DISTINCT FROM {$alias}.location_id)";
            $sql = str_replace('WITH levels AS (', "WITH chosen AS MATERIALIZED (SELECT * FROM inventory_reconcile_work WHERE (?::bigint IS NULL OR branch_id = ?::bigint) ORDER BY key LIMIT {$limit}), levels AS (", $sql);
            $sql = str_replace('SELECT * FROM stock_levels WHERE', 'SELECT * FROM stock_levels WHERE '.$matches('stock_levels').' AND', $sql);
            $sql = str_replace('FROM stock_movements WHERE', 'FROM stock_movements WHERE '.$matches('stock_movements').' AND', $sql);
            $sql = str_replace("\nSELECT (SELECT COUNT(*) FROM levels)", <<<'SQL'
, cleaned AS (
    DELETE FROM inventory_reconcile_work w USING chosen c
    WHERE w.key = c.key AND w.revision = c.revision
        AND NOT EXISTS (SELECT 1 FROM problems p WHERE p.branch_id = c.branch_id
            AND p.product_variant_id = c.product_variant_id AND p.location_id IS NOT DISTINCT FROM c.location_id)
    RETURNING w.key
)
SELECT (SELECT COUNT(*) FROM levels)
SQL, $sql);
        }
        $bindings = array_fill(0, $incremental ? 6 : 4, $branch);
        $result = DB::selectOne($sql, $bindings);

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
