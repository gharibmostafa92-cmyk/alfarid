<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\TreasuryTransferStatus;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class CashForecastTool implements AgentTool
{
    private const WINDOW_DAYS = 30;

    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'cash_forecast';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['treasuries.view', 'finance.reports.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'توقع what-if: رصيد كل خزنة لو اعتمدت التحويلات المعلقة، ومتوسط المصروف اليومي وعدد أيام التغطية لكل فرع.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'transfer_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    'window_days' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && isset($args['branch_id']) && (int) $args['branch_id'] !== $restricted) {
            return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
        }
        $branchId = $restricted ?? (isset($args['branch_id']) ? (int) $args['branch_id'] : null);
        $window = isset($args['window_days']) ? max(1, min(90, (int) $args['window_days'])) : self::WINDOW_DAYS;
        $onlyIds = isset($args['transfer_ids']) && is_array($args['transfer_ids'])
            ? array_values(array_filter(array_map('intval', $args['transfer_ids']), fn ($id) => $id > 0))
            : [];

        $transfers = TreasuryTransfer::query()->where('status', TreasuryTransferStatus::Pending)
            ->when($onlyIds !== [], fn ($q) => $q->whereKey($onlyIds))
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $branchId))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $branchId))))
            ->get(['id', 'from_treasury_id', 'to_treasury_id', 'amount']);

        $pendingIn = [];
        $pendingOut = [];
        foreach ($transfers as $transfer) {
            $amount = (float) $transfer->amount;
            $pendingOut[$transfer->from_treasury_id] = ($pendingOut[$transfer->from_treasury_id] ?? 0) + $amount;
            $pendingIn[$transfer->to_treasury_id] = ($pendingIn[$transfer->to_treasury_id] ?? 0) + $amount;
        }

        $treasuries = Treasury::query()->where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,name')->orderBy('id')->get(['id', 'branch_id', 'name', 'type', 'current_balance']);

        $dailyAvgByBranch = $this->dailyAverages($branchId, $window);

        $rows = [];
        $table = [];
        foreach ($treasuries as $treasury) {
            $current = (float) $treasury->current_balance;
            $in = $pendingIn[$treasury->id] ?? 0;
            $out = $pendingOut[$treasury->id] ?? 0;
            $projected = $current + $in - $out;
            $daily = $dailyAvgByBranch[$treasury->branch_id] ?? 0;
            $coverage = $daily > 0 ? $projected / $daily : null;
            $rows[] = [
                'treasury' => $this->presenter->untrusted($treasury->name, 150),
                'branch' => $this->presenter->untrusted($treasury->branch?->name ?? 'فرع #'.$treasury->branch_id, 150),
                'current' => round($current, 2),
                'pending_in' => round($in, 2),
                'pending_out' => round($out, 2),
                'projected' => round($projected, 2),
                'daily_expense_avg' => round($daily, 2),
                'coverage_days' => $coverage === null ? null : round($coverage, 1),
                'link' => $this->presenter->deepLink('tr', $treasury->id),
            ];
            $table[] = [
                $treasury->name,
                $this->presenter->money($current),
                $this->presenter->money($projected),
                $daily > 0 ? round($coverage ?? 0, 1).' يوم' : '—',
            ];
        }

        return $this->presenter->present($actor, [
            'window_days' => $window,
            'simulated_transfers' => $transfers->pluck('id')->all(),
            'daily_expense_avg_by_branch' => array_map(fn ($v) => round($v, 2), $dailyAvgByBranch),
            'treasuries' => $rows,
        ], $this->presenter->table(
            ['الخزنة', 'الحالي', 'المتوقع بعد الاعتماد', 'التغطية'],
            $table
        ));
    }

    /** @return array<int,float> */
    private function dailyAverages(?int $branchId, int $window): array
    {
        $from = now()->subDays($window)->toDateString();
        $sums = \App\Models\Expense::query()->whereNull('voided_at')
            ->where('expense_date', '>=', $from)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('branch_id, SUM(amount) AS total')->groupBy('branch_id')->pluck('total', 'branch_id');

        $out = [];
        foreach ($sums as $id => $total) {
            $out[(int) $id] = (float) $total / $window;
        }

        return $out;
    }
}
