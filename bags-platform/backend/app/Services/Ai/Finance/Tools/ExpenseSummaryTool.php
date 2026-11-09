<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\Expense;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use Carbon\Carbon;

final class ExpenseSummaryTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'expense_summary';
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
        return ['expenses.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إجمالي المصروفات حسب الفئة أو الفرع أو الخزينة لفترة مع مقارنة بالفترة السابقة وأعلى 5 فئات.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'group_by' => ['type' => 'STRING', 'enum' => ['category', 'branch', 'treasury']],
                    'from_date' => ['type' => 'STRING'],
                    'to_date' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'treasury_id' => ['type' => 'INTEGER'],
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

        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $to = ! empty($args['to_date']) ? Carbon::parse((string) $args['to_date'], $tz) : now($tz);
        $from = ! empty($args['from_date']) ? Carbon::parse((string) $args['from_date'], $tz) : $to->copy()->startOfMonth();
        $lengthDays = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1);

        $groupBy = (string) ($args['group_by'] ?? 'category');
        if (! in_array($groupBy, ['category', 'branch', 'treasury'], true)) {
            $groupBy = 'category';
        }

        $base = fn (Carbon $f, Carbon $t) => Expense::query()->whereNull('voided_at')
            ->whereBetween('expense_date', [$f->toDateString(), $t->toDateString()])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(isset($args['treasury_id']), fn ($q) => $q->where('treasury_id', (int) $args['treasury_id']));

        $column = match ($groupBy) {
            'branch' => 'branch_id',
            'treasury' => 'treasury_id',
            default => 'category',
        };
        $current = (clone $base($from, $to))->selectRaw("{$column} AS grp, SUM(amount) AS total, COUNT(*) AS cnt")
            ->groupBy('grp')->orderByDesc('total')->get();
        $previous = (clone $base($prevFrom, $prevTo))->selectRaw("{$column} AS grp, SUM(amount) AS total")
            ->groupBy('grp')->pluck('total', 'grp');

        $labels = $this->groupLabels($groupBy, $current->pluck('grp')->all());
        $groups = [];
        foreach ($current as $row) {
            $key = (string) $row->grp;
            $total = (float) $row->total;
            $prev = (float) ($previous[$row->grp] ?? $previous[$key] ?? 0);
            $groups[] = [
                'group' => $this->presenter->untrusted((string) ($labels[$key] ?? $key), 191),
                'total' => $total,
                'count' => (int) $row->cnt,
                'previous_total' => $prev,
                'change_pct' => $prev > 0 ? round((($total - $prev) / $prev) * 100, 1) : null,
            ];
        }

        $topCategories = Expense::query()->whereNull('voided_at')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('category, SUM(amount) AS total')->groupBy('category')
            ->orderByDesc('total')->limit(5)->get()
            ->map(fn ($r) => ['category' => $this->presenter->untrusted((string) $r->category, 191), 'total' => (float) $r->total])->values()->all();

        $table = array_map(fn ($g) => [
            is_array($g['group']) ? ($g['group']['untrusted_text'] ?? '') : (string) $g['group'],
            $this->presenter->money($g['total']),
            (string) $g['count'],
            $g['previous_total'] > 0 ? $this->presenter->money($g['previous_total']) : '—',
            $g['change_pct'] === null ? '—' : ($g['change_pct'] >= 0 ? '+' : '').$g['change_pct'].'%',
        ], $groups);

        return $this->presenter->present($actor, [
            'group_by' => $groupBy,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'groups' => $groups,
            'top_categories' => $topCategories,
        ], $this->presenter->table(
            ['المجموعة', 'الإجمالي', 'العدد', 'الفترة السابقة', 'التغير'],
            $table
        ));
    }

    /** @param list<mixed> $keys @return array<string,mixed> */
    private function groupLabels(string $groupBy, array $keys): array
    {
        if ($groupBy === 'branch') {
            return \App\Models\Branch::query()->whereKey($keys)->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        }
        if ($groupBy === 'treasury') {
            return \App\Models\Treasury::query()->whereKey($keys)->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        }

        return [];
    }
}
