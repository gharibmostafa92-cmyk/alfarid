<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class DailyCloseBriefingTool implements AgentTool
{
    private const DRAWER_FLOAT_KEEP = 1000.00;

    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'daily_close_briefing';
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
        return ['treasuries.view', 'expenses.view', 'finance.reports.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'ملخص قفلة اليوم للفرع: المبيعات النقدية والمصروفات والتحويلات والورديات المفتوحة والعجز/الزيادة، مع إجراءات سريعة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'date' => ['type' => 'STRING'],
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
        $date = isset($args['date']) && is_string($args['date']) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $args['date']) === 1
            ? $args['date']
            : now()->toDateString();

        $cashSales = (float) Order::query()
            ->where('payment_status', 'paid')->where('payment_method', 'cash')
            ->whereDate('created_at', $date)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->sum('grand_total');

        $expenses = (float) Expense::query()->whereNull('voided_at')->where('expense_date', $date)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $transferQuery = fn () => TreasuryTransfer::query()->whereDate('created_at', $date)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $branchId))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $branchId))));
        $transfersCreated = (clone $transferQuery())->count();
        $transfersPending = (clone $transferQuery())->where('status', TreasuryTransferStatus::Pending)->count();
        $transfersApproved = (clone $transferQuery())->where('status', TreasuryTransferStatus::Approved)->count();

        $shifts = PosShift::query()
            ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->with('cashier:id,name')->orderBy('id')->get();
        $shiftRows = [];
        $shortage = 0.0;
        $surplus = 0.0;
        foreach ($shifts as $shift) {
            $diff = $shift->cash_difference === null ? null : (float) $shift->cash_difference;
            if ($diff !== null && $diff < 0) {
                $shortage += -$diff;
            } elseif ($diff !== null && $diff > 0) {
                $surplus += $diff;
            }
            $shiftRows[] = [
                'number' => $shift->shift_number,
                'status' => $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status,
                'cashier' => $this->presenter->untrusted($shift->cashier?->name, 150),
                'expected' => $shift->expected_closing_cash === null ? null : (float) $shift->expected_closing_cash,
                'actual' => $shift->actual_closing_cash === null ? null : (float) $shift->actual_closing_cash,
                'difference' => $diff,
            ];
        }

        $suggested = $actor->can('treasury_transfers.create') ? $this->surplusTransfers($actor, $branchId) : [];
        $quickActions = [];
        if ($shifts->where('status', PosShiftStatus::ClosedPendingApproval)->isNotEmpty()) {
            $quickActions[] = ['label' => 'اعتمد الورديات', 'prompt' => 'اعتمد الورديات اللي مستنية التسوية'];
        }
        if ($suggested !== []) {
            $total = array_sum(array_column($suggested, 'amount'));
            $prompt = 'جهّز دفعة تحويل فائض الأدراج للرئيسية';
            if ($branchId !== null) {
                $prompt .= " للفرع رقم {$branchId}";
            }
            $quickActions[] = ['label' => "راجع تحويل فائض الأدراج ({$total})", 'prompt' => $prompt, 'submit' => true];
        }

        $table = [
            ['المبيعات النقدية', $this->presenter->money($cashSales)],
            ['المصروفات', $this->presenter->money($expenses)],
            ['التحويلات (منشأة/معلقة/معتمدة)', "{$transfersCreated}/{$transfersPending}/{$transfersApproved}"],
            ['الورديات المفتوحة', count($shiftRows)],
            ['العجز', $this->presenter->money($shortage)],
            ['الزيادة', $this->presenter->money($surplus)],
        ];

        return $this->presenter->present($actor, [
            'date' => $date,
            'branch_id' => $branchId,
            'cash_sales' => round($cashSales, 2),
            'expenses' => round($expenses, 2),
            'transfers' => ['created' => $transfersCreated, 'pending' => $transfersPending, 'approved' => $transfersApproved],
            'open_shifts' => $shiftRows,
            'shortage' => round($shortage, 2),
            'surplus' => round($surplus, 2),
            'suggested_transfers' => $suggested,
            'quick_actions' => $quickActions,
        ], array_merge($this->presenter->table(['البند', 'القيمة'], $table), ['quick_actions' => $quickActions]));
    }

    /** @return list<array{from_treasury_id:int,to_treasury_id:int,amount:float}> */
    public function surplusTransfers(User $actor, ?int $branchId): array
    {
        $restricted = $this->branches->restrictedBranch($actor);
        if ($restricted !== null && $branchId !== null && $branchId !== $restricted) {
            throw new \Illuminate\Auth\Access\AuthorizationException('الفرع خارج نطاقك.');
        }
        $branchId = $restricted ?? $branchId;
        $safes = Treasury::query()->where('is_active', true)->where('type', TreasuryType::MainSafe)
            ->whereNotNull('branch_id')->whereHas('branch', fn ($branch) => $branch->where('is_active', true))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')->pluck('id', 'branch_id');
        $out = [];
        $drawers = Treasury::query()->where('is_active', true)->where('type', TreasuryType::PosDrawer)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('branch_id', $safes->keys())
            ->whereDoesntHave('shifts', fn ($shifts) => $shifts->where('status', PosShiftStatus::Open))
            ->orderBy('id')->get(['id', 'branch_id', 'current_balance']);
        $pending = TreasuryTransfer::query()->whereIn('from_treasury_id', $drawers->modelKeys())
            ->where('status', TreasuryTransferStatus::Pending)
            ->selectRaw('from_treasury_id, SUM(amount) AS reserved')->groupBy('from_treasury_id')
            ->pluck('reserved', 'from_treasury_id');
        foreach ($drawers as $drawer) {
            $available = bcsub($drawer->current_balance, (string) ($pending[$drawer->id] ?? '0'), 2);
            $excess = bcsub($available, (string) self::DRAWER_FLOAT_KEEP, 2);
            if (bccomp($excess, '0', 2) > 0) {
                $out[] = ['from_treasury_id' => $drawer->id, 'to_treasury_id' => $safes[$drawer->branch_id], 'amount' => (float) $excess];
            }
        }

        return $out;
    }
}
