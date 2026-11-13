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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DailyCloseBriefingTool implements AgentTool
{
    private const DRAWER_FLOAT_KEEP = '1000.00';

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
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $date = isset($args['date']) && is_string($args['date']) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $args['date']) === 1
            ? $args['date']
            : \Carbon\CarbonImmutable::now($tz)->toDateString();

        $dayStart = \Carbon\CarbonImmutable::parse($date, $tz)->startOfDay();
        $startUtc = $dayStart->utc()->toDateTimeString();
        // Exclusive next-day bound: endOfDay()+toDateTimeString() dropped the
        // final sub-second of the business day.
        $endUtc = $dayStart->addDay()->utc()->toDateTimeString();

        $cashSalesGross = (string) (Order::query()
            ->where('payment_status', 'paid')->whereNull('return_of_order_id')
            ->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->selectRaw("COALESCE(SUM(COALESCE(cash_amount, CASE WHEN payment_method = 'cash' THEN grand_total ELSE 0 END)), 0) AS c")
            ->value('c') ?? '0');

        // POS returns are stored as separate refund orders; their cash tender is
        // cash leaving the drawer and must reduce the day's net cash sales.
        $cashRefunds = (string) (Order::query()
            ->whereNotNull('return_of_order_id')
            ->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(COALESCE(cash_amount, 0)), 0) AS c')
            ->value('c') ?? '0');
        $cashSales = BigDecimal::of($cashSalesGross)->minus(BigDecimal::of($cashRefunds));

        $expenses = BigDecimal::of((string) (Expense::query()->whereNull('voided_at')->where('expense_date', $date)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(amount), 0) AS c')
            ->value('c') ?? '0'));

        $transferQuery = fn () => TreasuryTransfer::query()
            ->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $branchId))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $branchId))));
        $transferCounts = $transferQuery()
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $transfersCreated = (int) $transferCounts->sum();
        $transfersPending = (int) ($transferCounts[TreasuryTransferStatus::Pending->value] ?? 0);
        $transfersApproved = (int) ($transferCounts[TreasuryTransferStatus::Approved->value] ?? 0);

        $shifts = PosShift::query()
            ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->with('cashier:id,name')->orderBy('id')->get();
        $shiftRows = [];
        $shortage = BigDecimal::zero();
        $surplus = BigDecimal::zero();
        foreach ($shifts as $shift) {
            $diff = $shift->cash_difference === null
                ? null
                : BigDecimal::of((string) $shift->cash_difference)->toScale(2, RoundingMode::HalfUp);
            if ($diff !== null && $diff->isNegative()) {
                $shortage = $shortage->plus($diff->abs());
            } elseif ($diff !== null && $diff->isPositive()) {
                $surplus = $surplus->plus($diff);
            }
            $shiftRows[] = [
                'number' => $shift->shift_number,
                'status' => $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status,
                'cashier' => $this->presenter->untrusted($shift->cashier?->name, 150),
                'expected' => $shift->expected_closing_cash === null ? null : (float) $shift->expected_closing_cash,
                'actual' => $shift->actual_closing_cash === null ? null : (float) $shift->actual_closing_cash,
                'difference' => $diff?->toFloat(),
            ];
        }

        $suggested = $actor->can('treasury_transfers.create') ? $this->surplusTransfers($actor, $branchId) : [];
        $quickActions = [];
        if ($shifts->where('status', PosShiftStatus::ClosedPendingApproval)->isNotEmpty()) {
            $quickActions[] = ['label' => 'اعتمد الورديات', 'prompt' => 'اعتمد الورديات اللي مستنية التسوية'];
        }
        if ($suggested !== []) {
            $total = array_reduce(
                $suggested,
                fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus((string) $row['amount']),
                BigDecimal::zero(),
            )->toScale(2, RoundingMode::HalfUp);
            $prompt = 'جهّز دفعة تحويل فائض الأدراج للرئيسية';
            if ($branchId !== null) {
                $prompt .= " للفرع رقم {$branchId}";
            }
            $quickActions[] = ['label' => "راجع تحويل فائض الأدراج ({$total})", 'prompt' => $prompt, 'submit' => true];
        }

        $table = [
            ['المبيعات النقدية', $this->presenter->money((string) $cashSales)],
            ['المصروفات', $this->presenter->money((string) $expenses)],
            ['التحويلات (منشأة/معلقة/معتمدة)', "{$transfersCreated}/{$transfersPending}/{$transfersApproved}"],
            ['الورديات المفتوحة', count($shiftRows)],
            ['العجز', $this->presenter->money((string) $shortage)],
            ['الزيادة', $this->presenter->money((string) $surplus)],
        ];

        return $this->presenter->present($actor, [
            'date' => $date,
            'branch_id' => $branchId,
            'cash_sales' => $cashSales->toScale(2, RoundingMode::HalfUp)->toFloat(),
            'expenses' => $expenses->toScale(2, RoundingMode::HalfUp)->toFloat(),
            'transfers' => ['created' => $transfersCreated, 'pending' => $transfersPending, 'approved' => $transfersApproved],
            'open_shifts' => $shiftRows,
            'shortage' => $shortage->toScale(2, RoundingMode::HalfUp)->toFloat(),
            'surplus' => $surplus->toScale(2, RoundingMode::HalfUp)->toFloat(),
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
            // Oldest active safe per branch, matching settleShiftToMainSafe().
            ->orderBy('id')->get(['id', 'branch_id'])->unique('branch_id')->pluck('id', 'branch_id');
        $out = [];
        $drawers = Treasury::query()->where('is_active', true)->where('type', TreasuryType::PosDrawer)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('branch_id', $safes->keys())
            ->whereDoesntHave('shifts', fn ($shifts) => $shifts->whereIn('status', [
                PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval,
            ]))
            ->orderBy('id')->get(['id', 'branch_id', 'current_balance']);
        $pending = TreasuryTransfer::query()->whereIn('from_treasury_id', $drawers->modelKeys())
            ->where('status', TreasuryTransferStatus::Pending)
            ->selectRaw('from_treasury_id, SUM(amount) AS reserved')->groupBy('from_treasury_id')
            ->pluck('reserved', 'from_treasury_id');
        foreach ($drawers as $drawer) {
            $available = BigDecimal::of((string) $drawer->current_balance)
                ->minus((string) ($pending[$drawer->id] ?? '0'))
                ->toScale(2, RoundingMode::HalfUp);
            $excess = $available->minus(self::DRAWER_FLOAT_KEEP);
            if ($excess->isPositive()) {
                $out[] = ['from_treasury_id' => $drawer->id, 'to_treasury_id' => $safes[$drawer->branch_id], 'amount' => $excess->toFloat()];
            }
        }

        return $out;
    }
}
