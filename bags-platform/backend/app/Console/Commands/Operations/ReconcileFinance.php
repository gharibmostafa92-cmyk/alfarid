<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Models\Branch;
use App\Models\FinanceReconcileRun;
use App\Services\Finance\StatementBudget;
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
    protected $signature = 'finance:reconcile
        {--branch_id= : قصر فحص الخزائن على فرع واحد}
        {--wallet-chunk=50000 : عدد العملاء في كل دفعة لفحص المحافظ}';

    protected $description = 'مطابقة أرصدة الخزائن والمحافظ مع دفاتر حركاتها وكشف أي كتابة مباشرة (عند تمرير --branch_id تُفحص خزائن الفرع فقط دون محافظ العملاء العامة)';

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

        return $this->reconcile($branch === null ? null : (int) $branch);
    }

    private function reconcile(?int $branch): int
    {
        $chunk = filter_var($this->option('wallet-chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($chunk === false) {
            $this->error('wallet-chunk يجب أن يكون بين 1 و1000000.');

            return self::INVALID;
        }

        // One SQL statement sees one committed snapshot, even while sales continue.
        // Treasury and wallet audits run separately so a large wallet ledger cannot
        // abort the treasury audit, and wallets are scanned in bounded id ranges.
        $treasuryResult = StatementBudget::run(120000, fn () => DB::selectOne(<<<'SQL'
            WITH t_ledger AS (
                SELECT tt.*, LAG(tt.balance_after, 1, 0::numeric) OVER (
                    PARTITION BY tt.treasury_id ORDER BY tt.id
                ) AS prev_balance,
                SUM(tt.amount) OVER (PARTITION BY tt.treasury_id) AS ledger_total
                FROM treasury_transactions tt
                WHERE (?::bigint IS NULL OR tt.treasury_id IN (SELECT t.id FROM treasuries t WHERE t.branch_id = ?::bigint))
            ), t_totals AS (
                SELECT DISTINCT treasury_id, ledger_total FROM t_ledger
            ), t_problems AS (
                SELECT 'treasury_chain' AS issue, l.id AS ref_id, l.treasury_id, NULL::bigint AS customer_id,
                    l.prev_balance AS expected, (l.balance_after - l.amount) AS actual
                FROM t_ledger l WHERE (l.balance_after - l.amount) <> l.prev_balance
                UNION ALL
                SELECT 'treasury_balance', t.id, t.id, NULL, COALESCE(s.ledger_total, 0::numeric), COALESCE(t.current_balance, 0::numeric)
                FROM treasuries t LEFT JOIN t_totals s ON s.treasury_id = t.id
                WHERE (?::bigint IS NULL OR t.branch_id = ?::bigint)
                    AND COALESCE(t.current_balance, 0::numeric) <> COALESCE(s.ledger_total, 0::numeric)
            )
            SELECT (SELECT COUNT(*) FROM treasuries WHERE (?::bigint IS NULL OR branch_id = ?::bigint)) AS checked,
                (SELECT COUNT(*) FROM t_problems) AS problem_count,
                COALESCE((SELECT json_agg(p) FROM (SELECT * FROM t_problems ORDER BY issue, ref_id LIMIT 100) p), '[]'::json) AS samples
            SQL, [$branch, $branch, $branch, $branch, $branch, $branch]), readOnly: true);

        $checked = (int) $treasuryResult->checked;
        $problemCount = (int) $treasuryResult->problem_count;
        $samples = json_decode((string) $treasuryResult->samples, true, flags: JSON_THROW_ON_ERROR);

        if ($branch === null) {
            $walletSql = <<<'SQL'
                WITH w_ledger AS (
                    SELECT wt.*, LAG(wt.balance_after, 1, 0::numeric) OVER (
                        PARTITION BY wt.customer_id ORDER BY wt.id
                    ) AS prev_balance,
                    SUM(wt.amount) OVER (PARTITION BY wt.customer_id) AS ledger_total
                    FROM customer_wallet_transactions wt
                    WHERE wt.customer_id >= ? AND wt.customer_id < ?
                ), w_totals AS (
                    SELECT DISTINCT customer_id, ledger_total FROM w_ledger
                ), w_problems AS (
                    SELECT 'wallet_chain' AS issue, l.id AS ref_id, NULL::bigint AS treasury_id, l.customer_id,
                        l.prev_balance AS expected, (l.balance_after - l.amount) AS actual
                    FROM w_ledger l WHERE (l.balance_after - l.amount) <> l.prev_balance
                    UNION ALL
                    SELECT 'wallet_balance', c.id, NULL, c.id, COALESCE(s.ledger_total, 0::numeric), COALESCE(c.wallet_balance, 0::numeric)
                    FROM customers c LEFT JOIN w_totals s ON s.customer_id = c.id
                    WHERE c.id >= ? AND c.id < ?
                        AND COALESCE(c.wallet_balance, 0::numeric) <> COALESCE(s.ledger_total, 0::numeric)
                )
                SELECT (SELECT COUNT(*) FROM customers WHERE id >= ? AND id < ?) AS checked,
                    (SELECT COUNT(*) FROM w_problems) AS problem_count,
                    COALESCE((SELECT json_agg(p) FROM (SELECT * FROM w_problems ORDER BY issue, ref_id LIMIT %d) p), '[]'::json) AS samples
                SQL;
            $range = DB::selectOne('SELECT COALESCE(MIN(id), 0) AS min_id, COALESCE(MAX(id), 0) AS max_id FROM customers');
            $minId = (int) $range->min_id;
            $maxId = (int) $range->max_id;
            for ($lo = $minId; $minId > 0 && $lo <= $maxId; $lo += $chunk) {
                $hi = $lo + $chunk;
                $sampleLimit = max(0, 100 - count($samples));
                $wallet = StatementBudget::run(
                    120000,
                    fn () => DB::selectOne(sprintf($walletSql, $sampleLimit), [$lo, $hi, $lo, $hi, $lo, $hi]),
                    readOnly: true,
                );

                $checked += (int) $wallet->checked;
                $problemCount += (int) $wallet->problem_count;
                foreach (json_decode((string) $wallet->samples, true, flags: JSON_THROW_ON_ERROR) as $problem) {
                    if (count($samples) < 100) {
                        $samples[] = $problem;
                    }
                }
            }
        }

        FinanceReconcileRun::query()->create([
            'branch_id' => $branch,
            'checked' => $checked,
            'problem_count' => $problemCount,
            'samples' => $samples,
        ]);
        FinanceReconcileRun::query()->where('created_at', '<', now()->subDays(90))->delete();

        if ($problemCount > 0) {
            foreach ($samples as $problem) {
                $this->error(json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            Log::error('Finance reconciliation failed', ['problem_count' => $problemCount, 'samples' => $samples]);
            $this->error("المطابقة فشلت: {$problemCount} فرق في {$checked} رصيد.");

            return self::FAILURE;
        }

        $this->info("المطابقة سليمة: {$checked} رصيد مطابق للدفتر.");

        return self::SUCCESS;
    }
}
