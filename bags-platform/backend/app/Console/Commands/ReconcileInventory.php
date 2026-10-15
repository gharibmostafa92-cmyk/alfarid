<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مطابقة دورية بين الدفتر والأرصدة (M-8): القاعدة تضمن صحة كل حركة على حدة،
 * لكن أي كتابة مباشرة على stock_levels (والـ fillable يسمح بها) لا يكشفها
 * شيء آخر. الفحص يتحقق لكل وعاء من أمرين:
 * 1. رصيد stock_levels يساوي آخر balance_after في الدفتر لنفس الوعاء.
 * 2. تسلسل الدفتر متصل: balance_before لكل حركة يساوي balance_after لسابقتها.
 * يُشغَّل دوريًا (جدولة ليلية) ويُرجع كود فشل عند وجود أي فرق للتنبيه.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--branch_id= : قصر الفحص على فرع واحد}';

    protected $description = 'مطابقة أرصدة المخزون مع دفتر الحركات وكشف أي كتابة مباشرة';

    public function handle(): int
    {
        $branch = $this->option('branch_id');
        if ($branch !== null && filter_var($branch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('branch_id يجب أن يكون عددًا صحيحًا موجبًا.');

            return self::INVALID;
        }

        // One SQL statement sees one committed snapshot, even while sales continue.
        // Window functions also catch broken chains and movements without a stock row.
        $result = DB::selectOne(<<<'SQL'
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
SQL, [$branch, $branch, $branch, $branch]);

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
