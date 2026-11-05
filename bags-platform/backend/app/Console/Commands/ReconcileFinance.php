<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\FinanceReconcileRun;
use App\Services\StatementBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly ledger integrity check (Phase 5): for every treasury,
 * current_balance must equal SUM(amount) and the balance_after chain must
 * be continuous (each movement = previous + amount). Same for every
 * customer wallet. Stores the result for ledger_integrity_check + morning card.
 */
class ReconcileFinance extends Command
{
    protected $signature = 'finance:reconcile {--branch_id= : قصر فحص الخزائن على فرع واحد}';

    protected $description = 'مطابقة أرصدة الخزائن والمحافظ مع دفاتر حركاتها وكشف أي كتابة مباشرة';

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

        return StatementBudget::run(300000, fn (): int => $this->reconcile($branch === null ? null : (int) $branch));
    }

    private function reconcile(?int $branch): int
    {
        // One SQL statement sees one committed snapshot, even while sales continue.
        $sql = <<<'SQL'
WITH t_ledger AS (
    SELECT tt.*, LAG(tt.balance_after, 1, 0::numeric) OVER (
        PARTITION BY tt.treasury_id ORDER BY tt.id
    ) AS prev_balance
    FROM treasury_transactions tt
    WHERE (?::bigint IS NULL OR tt.treasury_id IN (SELECT t.id FROM treasuries t WHERE t.branch_id = ?::bigint))
), t_sums AS (
    SELECT treasury_id, SUM(amount) AS total FROM treasury_transactions
    WHERE (?::bigint IS NULL OR treasury_id IN (SELECT id FROM treasuries WHERE branch_id = ?::bigint))
    GROUP BY treasury_id
), t_problems AS (
    SELECT 'treasury_chain' AS issue, l.id AS ref_id, l.treasury_id, NULL::bigint AS customer_id,
        l.prev_balance AS expected, (l.balance_after - l.amount) AS actual
    FROM t_ledger l WHERE (l.balance_after - l.amount) <> l.prev_balance
    UNION ALL
    SELECT 'treasury_balance', t.id, t.id, NULL, COALESCE(s.total, 0::numeric), COALESCE(t.current_balance, 0::numeric)
    FROM treasuries t LEFT JOIN t_sums s ON s.treasury_id = t.id
    WHERE (?::bigint IS NULL OR t.branch_id = ?::bigint)
        AND COALESCE(t.current_balance, 0::numeric) <> COALESCE(s.total, 0::numeric)
), w_ledger AS (
    SELECT wt.*, LAG(wt.balance_after, 1, 0::numeric) OVER (
        PARTITION BY wt.customer_id ORDER BY wt.id
    ) AS prev_balance
    FROM customer_wallet_transactions wt
), w_sums AS (
    SELECT customer_id, SUM(amount) AS total FROM customer_wallet_transactions GROUP BY customer_id
), w_problems AS (
    SELECT 'wallet_chain' AS issue, l.id AS ref_id, NULL::bigint AS treasury_id, l.customer_id,
        l.prev_balance AS expected, (l.balance_after - l.amount) AS actual
    FROM w_ledger l WHERE (l.balance_after - l.amount) <> l.prev_balance
    UNION ALL
    SELECT 'wallet_balance', c.id, NULL, c.id, COALESCE(s.total, 0::numeric), COALESCE(c.wallet_balance, 0::numeric)
    FROM customers c LEFT JOIN w_sums s ON s.customer_id = c.id
    WHERE COALESCE(c.wallet_balance, 0::numeric) <> COALESCE(s.total, 0::numeric)
), problems AS (
    SELECT * FROM t_problems UNION ALL SELECT * FROM w_problems
)
SELECT (SELECT COUNT(*) FROM treasuries WHERE (?::bigint IS NULL OR branch_id = ?::bigint))
    + (SELECT COUNT(*) FROM customers) AS checked,
    (SELECT COUNT(*) FROM problems) AS problem_count,
    COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY issue, ref_id LIMIT 100) p), '[]'::json) AS samples
SQL;
        $result = DB::selectOne($sql, [$branch, $branch, $branch, $branch, $branch, $branch, $branch, $branch]);

        FinanceReconcileRun::query()->create([
            'branch_id' => $branch,
            'checked' => (int) $result->checked,
            'problem_count' => (int) $result->problem_count,
            'samples' => json_decode((string) $result->samples, true) ?? [],
        ]);
        FinanceReconcileRun::query()->where('created_at', '<', now()->subDays(14))->delete();

        if ((int) $result->problem_count > 0) {
            $samples = json_decode((string) $result->samples, true, flags: JSON_THROW_ON_ERROR);
            foreach ($samples as $problem) {
                $this->error(json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            Log::error('Finance reconciliation failed', ['problem_count' => (int) $result->problem_count, 'samples' => $samples]);
            $this->error("المطابقة فشلت: {$result->problem_count} فرق في {$result->checked} رصيد.");

            return self::FAILURE;
        }

        $this->info("المطابقة سليمة: {$result->checked} رصيد مطابق للدفتر.");

        return self::SUCCESS;
    }
}
