<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\TreasuryTransferStatus;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class CashPositionTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'cash_position';
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
            'description' => 'السيولة حسب الفرع: كاش وبنك والتحويلات المعلقة والصافي المتوقع لو اتعتمدت.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
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

        $treasuries = Treasury::query()->where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'branch_id', 'type', 'current_balance']);

        $pendingOut = TreasuryTransfer::query()->where('status', TreasuryTransferStatus::Pending)
            ->selectRaw('from_treasury_id AS treasury_id, SUM(amount) AS total')
            ->groupBy('from_treasury_id')->pluck('total', 'treasury_id');
        $pendingIn = TreasuryTransfer::query()->where('status', TreasuryTransferStatus::Pending)
            ->selectRaw('to_treasury_id AS treasury_id, SUM(amount) AS total')
            ->groupBy('to_treasury_id')->pluck('total', 'treasury_id');

        $byBranch = [];
        foreach ($treasuries as $t) {
            $key = $t->branch_id ?? 0;
            $byBranch[$key] ??= ['cash' => 0.0, 'bank' => 0.0, 'pending_in' => 0.0, 'pending_out' => 0.0];
            $type = $t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type;
            if ($type === 'bank_account') {
                $byBranch[$key]['bank'] += (float) $t->current_balance;
            } else {
                $byBranch[$key]['cash'] += (float) $t->current_balance;
            }
            $byBranch[$key]['pending_in'] += (float) ($pendingIn[$t->id] ?? 0);
            $byBranch[$key]['pending_out'] += (float) ($pendingOut[$t->id] ?? 0);
        }

        $names = Branch::query()->whereKey(array_keys($byBranch))->pluck('name', 'id');
        $rows = [];
        $totals = ['cash' => 0.0, 'bank' => 0.0, 'pending_in' => 0.0, 'pending_out' => 0.0, 'projected' => 0.0];
        foreach ($byBranch as $id => $b) {
            $projected = $b['cash'] + $b['bank'] + $b['pending_in'] - $b['pending_out'];
            foreach (['cash', 'bank', 'pending_in', 'pending_out'] as $k) {
                $totals[$k] += $b[$k];
            }
            $totals['projected'] += $projected;
            $rows[] = [
                'branch' => $id === 0 ? 'مركزية' : ($names[$id] ?? 'فرع #'.$id),
                'cash' => round($b['cash'], 2),
                'bank' => round($b['bank'], 2),
                'pending_in' => round($b['pending_in'], 2),
                'pending_out' => round($b['pending_out'], 2),
                'projected' => round($projected, 2),
            ];
        }

        $table = array_map(fn ($r) => [
            $r['branch'],
            $this->presenter->money($r['cash']),
            $this->presenter->money($r['bank']),
            $this->presenter->money($r['pending_in']),
            $this->presenter->money($r['pending_out']),
            $this->presenter->money($r['projected']),
        ], $rows);

        return $this->presenter->present($actor, [
            'branches' => $rows,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'as_of' => now(config('app.business_timezone', 'Africa/Cairo'))->toIso8601String(),
        ], array_merge(['type' => 'cash_position'], [
            'branches' => $rows,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'table' => $this->presenter->table(
                ['الفرع', 'كاش', 'بنك', 'وارد معلق', 'صادر معلق', 'المتوقع'],
                $table
            ),
        ]));
    }
}
